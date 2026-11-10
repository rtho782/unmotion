<?php
// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
declare(strict_types=1);
require_once __DIR__.'/xml-canonical.php';

/** Structural checks only: the native start hook must never contact libvirt. */
function unmLiveTpmRoots(): array {return ['/etc/libvirt/qemu/swtpm/tpm-states','/etc/libvirt/qemu/swtpm','/var/lib/libvirt/swtpm','/var/lib/libvirt/qemu/swtpm','/etc/libvirt/swtpm'];}
function unmLiveTpmPathAllowed(string $path,string $uuid,bool $cycleOnly=false): bool {
    if(!preg_match('/\A[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\z/D',$uuid))return false;
    foreach(unmLiveTpmRoots() as $root){
        if(preg_match('~\A'.preg_quote($root,'~').'/unmotion-live-[a-f0-9]{24}/'.preg_quote($uuid,'~').'\z~D',$path))return true;
        if(!$cycleOnly&&in_array($path,[$root.'/'.$uuid,$root.'/'.$uuid.'/tpm2'],true))return true;
    }return false;
}
function unmLiveTpmXml(DOMXPath $xp,string $uuid): ?array {
    $nodes=$xp->query('/domain/devices/tpm');if(!$nodes->length)return null;
    if($nodes->length!==1)throw new RuntimeException('Live migration supports one software TPM 2.0.');$tpm=$nodes->item(0);$backend=$xp->query('./backend',$tpm);
    if(!in_array($tpm->getAttribute('model'),['tpm-tis','tpm-crb'],true)||$backend->length!==1)throw new RuntimeException('Live TPM requires the tpm-tis or tpm-crb software emulator.');$backend=$backend->item(0);
    if($backend->getAttribute('type')!=='emulator'||$backend->getAttribute('version')!=='2.0'||!in_array($backend->getAttribute('persistent_state'),['','yes','no'],true))throw new RuntimeException('Live TPM requires an explicit software TPM 2.0, not passthrough or an external emulator.');
    foreach($tpm->attributes as $a)if($a->name!=='model')throw new RuntimeException('Unsupported live TPM option.');
    foreach($backend->attributes as $a)if(!in_array($a->name,['type','version','persistent_state'],true))throw new RuntimeException('Unsupported live TPM backend option.');
    foreach($xp->query('./*',$tpm) as $child)if(!in_array($child->tagName,['backend','alias','address'],true))throw new RuntimeException('Unsupported live TPM device element.');
    foreach($xp->query('./*',$backend) as $child)if(!in_array($child->tagName,['source','active_pcr_banks'],true))throw new RuntimeException('Encrypted or custom-profile TPM state is not supported by live migration in this beta.');
    $source=$xp->query('./source',$backend);if($source->length>1)throw new RuntimeException('Duplicated TPM storage.');$path=null;
    if($source->length){$source=$source->item(0);$path=$source->getAttribute('path');if($source->getAttribute('type')!=='dir'||!unmLiveTpmPathAllowed($path,$uuid)||$source->getElementsByTagName('*')->length)throw new RuntimeException('Live TPM requires an exact UUID-scoped local libvirt state directory.');foreach($source->attributes as $a)if(!in_array($a->name,['type','path'],true))throw new RuntimeException('Unsupported TPM storage option.');}
    $banks=$xp->query('./active_pcr_banks',$backend);if($banks->length>1)throw new RuntimeException('Duplicated TPM PCR configuration.');
    foreach($banks as $bankList){if($bankList->attributes->length)throw new RuntimeException('Unsupported TPM PCR configuration.');$seen=[];foreach($bankList->childNodes as $bank)if($bank instanceof DOMElement){if(!in_array($bank->tagName,['sha1','sha256','sha384','sha512'],true)||isset($seen[$bank->tagName])||$bank->attributes->length||$bank->getElementsByTagName('*')->length||trim($bank->textContent)!=='')throw new RuntimeException('Unsupported TPM PCR bank.');$seen[$bank->tagName]=true;}}
    $copy=$tpm->cloneNode(true);foreach(iterator_to_array($copy->childNodes) as $child)if($child instanceof DOMElement&&in_array($child->tagName,['alias','address'],true))$copy->removeChild($child);
    // The synchronous hook parses pretty XML without NOBLANKS, whereas fresh
    // preflight strips indentation. Ignore only whitespace-only text here;
    // keep every device/attribute/PCR/storage setting in the binding hash.
    $strip=static function(DOMNode $node)use(&$strip): void {foreach(iterator_to_array($node->childNodes) as $child){if($child instanceof DOMText&&trim($child->nodeValue)==='')$node->removeChild($child);elseif($child instanceof DOMElement)$strip($child);}};$strip($copy);
    return ['model'=>$tpm->getAttribute('model'),'version'=>'2.0','path'=>$path,'xmlHash'=>hash('sha256',unmXmlCanonical($copy))];
}

/** Fresh tool evidence, not a cache and never called by the native hook. */
function unmLiveTpmPlatform(): ?array {
    if(!unmTool('swtpm'))return null;
    $r=unmLiveCommand(['swtpm','socket','--print-capabilities','--tpm2']);$caps=json_decode($r,true,16,JSON_THROW_ON_ERROR);
    if(($caps['type']??'')!=='swtpm'||!is_string($caps['version']??null)||version_compare($caps['version'],'0.7.0','<')||!in_array('tpm-2.0',$caps['features']??[],true)||!in_array('nvram-backend-dir',$caps['features']??[],true))throw new RuntimeException('swtpm lacks the required TPM 2.0 directory backend.');
    $tool=trim(unmLiveCommand(['which','swtpm']));$library=realpath('/usr/lib64/libtpms.so.0');
    if(!str_starts_with($tool,'/usr/')||!is_file($tool)||!$library||!str_starts_with($library,'/usr/lib64/')||!is_file($library))throw new RuntimeException('Cannot verify the local swtpm/libtpms implementation.');
    return ['version'=>$caps['version'],'swtpmSha256'=>hash_file('sha256',$tool),'libtpmsSha256'=>hash_file('sha256',$library)];
}
function unmLiveTpmCompatible(?array $tpm,?array $source,?array $destination): void {
    if($tpm!==null&&(!$source||$source!==$destination))throw new RuntimeException('Live TPM migration requires matching swtpm and libtpms builds on both hosts. Update both Unraid hosts; Cold/Warm Move remains separate.');
}
function unmLiveTpmSourceEvidence(string $uuid,string $xml,?array $tpm): void {
    if($tpm===null)return;$path=$tpm['path'];
    if($path===null){$path=unmTpmStatePath($uuid,$xml,true);$path.='/tpm2';}
    $real=realpath($path);if($real===false||!is_dir($real)||!unmLiveTpmPathAllowed($real,$uuid))throw new RuntimeException('TPM state is missing or outside UUID-scoped local storage.');
    unmHostStateNoNestedMounts($real);unmLiveFileSystem($real);
    $seen=[];foreach(glob('/proc/[0-9]*/cmdline')?:[] as $cmdline){$exe=(string)@readlink(dirname($cmdline).'/exe');if(basename($exe)!=='swtpm')continue;$args=explode("\0",rtrim((string)@file_get_contents($cmdline),"\0"));$i=array_search('--tpmstate',$args,true);if($i===false)continue;$state=(string)($args[$i+1]??'');if(!preg_match('~\A(?:dir=|backend-uri=dir://)([^,]+)~',$state,$m)||realpath($m[1])!==$real)continue;$seen[]=unmProcessSnapshot((int)basename(dirname($cmdline)));}
    if(count($seen)!==1||!$seen[0])throw new RuntimeException('Cannot prove one running software TPM owns this exact state directory.');
}
function unmLiveTpmDestination(string $uuid,string $id): string {
    return '/etc/libvirt/qemu/swtpm/tpm-states/unmotion-'.unmLiveId($id).'/'.unmTransportUuid($uuid);
}
function unmLiveTpmReserve(string $path,string $uuid): void {
    if(!unmLiveTpmPathAllowed($path,$uuid,true)||file_exists($path)||is_link($path)||file_exists(dirname($path))||is_link(dirname($path)))throw new RuntimeException('Destination TPM reservation already exists or is invalid.');
    $base=dirname(dirname($path));if(realpath($base)!==$base||!is_dir($base))throw new RuntimeException('Destination libvirt TPM storage is unavailable.');
    if(!mkdir(dirname($path),0700)||!mkdir($path,0700))throw new RuntimeException('Cannot reserve independent destination TPM storage.');
    // No files are copied from a running emulator. QEMU transfers permanent,
    // volatile and saved state through its authenticated migration stream.
    unmLiveCommand(['sync','-f',dirname($path)]);
}
