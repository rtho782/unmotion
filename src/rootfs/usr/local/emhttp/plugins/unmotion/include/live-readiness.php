<?php
// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
declare(strict_types=1);

/** All independent structural blockers; display evidence never grants a start. */
function unmLiveXmlBlockers(string $xml): array {
    try{$d=unmCpuCompatibilityXml($xml);$xp=new DOMXPath($d);$uuid=unmTransportUuid(trim($xp->evaluate('string(/domain/uuid)')));}catch(Throwable $e){return [['code'=>'xml','message'=>$e->getMessage()]];}
    $checks=[
        'identity'=>static function()use($xp,$d){foreach(['/domain/name','/domain/uuid','/domain/memory','/domain/vcpu','/domain/os/type','/domain/cpu'] as $required)if($xp->query($required)->length!==1)throw new RuntimeException('Duplicated or missing VM identity/resources.');$name=trim($xp->evaluate('string(/domain/name)'));if($d->documentElement->getAttribute('type')!=='kvm'||$name===''||strlen($name)>128||str_contains($name,'/')||preg_match('/[\x00-\x1f]/',$name))throw new RuntimeException('Unsupported live domain identity.');},
        'machine'=>static function()use($xp){if(!preg_match('/\A(?:pc-q35|pc-i440fx)-[0-9]+\.[0-9]+\z/D',$xp->evaluate('string(/domain/os/type/@machine)'))||$xp->evaluate('string(/domain/os/type/@arch)')!=='x86_64')throw new RuntimeException('Use an exact versioned x86_64 Q35/i440fx machine.');},
        'cpu'=>static fn()=>unmLiveCpu($xml),
        'firmware'=>static fn()=>unmLiveFirmwareXml($xp,$uuid),
        'tpm'=>static fn()=>unmLiveTpmXml($xp,$uuid),
        'storage'=>static fn()=>unmLiveDisksXml($xp),
        'devices'=>static function()use($xp){if($xp->query('/domain/devices/hostdev|/domain/devices/filesystem|/domain/devices/shmem|/domain/devices/redirdev|/domain/devices/memory|/domain/memoryBacking/hugepages|/domain/launchSecurity|/domain/cpu/numa')->length)throw new RuntimeException('Passthrough, shared memory, huge pages, memory hotplug or NUMA is present.');},
        'network'=>static function()use($xp){foreach($xp->query('/domain/devices/interface') as $nic)if($nic->getAttribute('type')!=='bridge'||$xp->query('./virtualport|./bandwidth|./vlan|./filterref|./script',$nic)->length)throw new RuntimeException('Use ordinary bridge NICs without host scripts, VLAN/filter or virtual-port settings.');},
        'host-pinning'=>static function()use($xp){if($xp->query('/domain/cputune|/domain/numatune|/domain/cpu/cache|/domain/cpu/maxphysaddr')->length)throw new RuntimeException('Host CPU/NUMA pinning or host-specific CPU cache/address settings are present.');},
        'display'=>static function()use($xp){if($xp->query('/domain/devices/graphics[@type!="vnc"]|/domain/devices/graphics/gl[@enable="yes"]|/domain/devices/video/model/acceleration[@accel3d="yes"]|/domain/devices/audio[@type!="none"]')->length)throw new RuntimeException('Use VNC/headless display without host GPU acceleration or host audio.');},
        'extensions'=>static function()use($xp){foreach($xp->query('//*') as $node)if($node->namespaceURI&&!in_array($node->namespaceURI,['http://unraid','unraid'],true))throw new RuntimeException('Custom executable extensions or managed recovery metadata are present.');},
        'resources'=>static function()use($xp){if($xp->evaluate('string(/domain/memory/@unit)')!=='KiB'||$xp->query('/domain/vcpu[@current and @current != text()]')->length||(int)$xp->evaluate('string(/domain/vcpu)')<1||(int)$xp->evaluate('string(/domain/memory)')<32768)throw new RuntimeException('Unsupported memory/vCPU units or hotplug configuration.');},
    ];$out=[];
    foreach($checks as $code=>$check)try{$check();}catch(Throwable $e){$out[]=['code'=>$code,'message'=>$e->getMessage()];}
    // The authoritative inspector remains a backstop when all categories pass.
    if(!$out)try{unmLiveInspectXml($xml);}catch(Throwable $e){$out[]=['code'=>'domain','message'=>$e->getMessage()];}
    return $out;
}
function unmLivePreviewPublic(array $pre): array {
    $s=$pre['spec'];$disks=unmLiveDisks($s);
    return ['vmUuid'=>$pre['vmUuid'],'vmName'=>$pre['vmName'],'peerId'=>$pre['peerId'],'machine'=>$s['machine'],'memoryMiB'=>$s['memoryKiB']/1024,'vcpus'=>$s['vcpus'],'diskGiB'=>round(unmLiveDiskTotal($disks)/1073741824,2),'diskCount'=>count($disks),'format'=>implode('/',array_unique(array_column($disks,'format'))),'disks'=>array_map(static fn($disk)=>['target'=>$disk['target'],'format'=>$disk['format'],'sizeGiB'=>round($disk['bytes']/1073741824,2)],$disks),'tpm'=>$s['tpm']===null?'none':'Software TPM 2.0','warnings'=>$pre['warnings']];
}
function unmLiveReadiness(string $uuid,string $peerId): array {
    $uuid=unmTransportUuid($uuid);$blockers=[];$add=static function(string $code,string $message)use(&$blockers){if(!in_array($message,array_column($blockers,'message'),true))$blockers[]=['code'=>$code,'message'=>$message];};
    try{$xml=unmLiveCommand(['virsh','dumpxml',$uuid,'--migratable','--security-info']);$blockers=unmLiveXmlBlockers($xml);}catch(Throwable $e){$add('xml',$e->getMessage());}
    try{$state=unmLiveVmState($uuid);if(!in_array($state['state'],['running','idle'],true))$add('power','Live migration requires a running VM. Apply pending CPU/XML changes with a full stop/start first, not a guest reboot.');}catch(Throwable $e){$add('power',$e->getMessage());}
    try{
        $peer=unmPeer($peerId);if(($peer['pairingState']??'')!=='paired')throw new RuntimeException('Completed reciprocal pairing is required.');unmAssertRemotePeerHost($peer['host']);
        $r=unmRemote($peer,'/usr/local/sbin/unmotion-agent capabilities',20);$caps=$r['code']===0?json_decode($r['stdout'],true):null;
        if(($caps['hostId']??'')!==$peer['hostId']||($caps['pluginVersion']??'')!==UNM_VERSION||($caps['liveMigrationVersion']??null)!==3)throw new RuntimeException('Update both peers to matching '.UNM_VERSION.' for this live-migration contract. Ordinary protocol-7 Cold/Warm Move compatibility is unchanged.');
    }catch(Throwable $e){$add('peer',$e->getMessage());}
    $preview=null;
    if(!$blockers)try{$preview=unmLivePreviewPublic(unmLivePreview($uuid,$peerId));}catch(Throwable $e){$add('fresh-preflight',$e->getMessage());}
    return ['success'=>true,'ready'=>!$blockers,'observedAt'=>gmdate('c'),'blockers'=>$blockers,'preview'=>$preview,'alternative'=>'Cold/Warm Move use a powered-off handover and separate compatibility checks. This report does not authorize a migration; Start rechecks both hosts.'];
}
