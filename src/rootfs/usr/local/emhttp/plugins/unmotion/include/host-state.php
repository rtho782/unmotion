<?php
declare(strict_types=1);

// Post-recovery beta4: shared discovery and exact ownership evidence.
function unmTpmRoots(): array {
    return ['/etc/libvirt/qemu/swtpm/tpm-states','/etc/libvirt/qemu/swtpm','/var/lib/libvirt/swtpm','/var/lib/libvirt/qemu/swtpm','/etc/libvirt/swtpm'];
}
function unmTpmPathAllowed(string $path,string $uuid): bool {
    if(!preg_match('/^[a-f0-9-]{36}$/i',$uuid)||basename($path)!==$uuid||preg_match('~[\x00-\x1f\x7f]|/(?:\.|\.\.)(?:/|$)~',$path))return false;
    foreach(unmTpmRoots() as $root) {
        if($path===$root.'/'.$uuid)return true;
        $real=realpath($root);
        if($real!==false&&$path===$real.'/'.$uuid)return true;
    }
    return false;
}
function unmTpmStatePath(string $uuid,string $xml,bool $required=true): string {
    $doc=new DOMDocument();
    if(!@$doc->loadXML($xml,LIBXML_NONET)||$doc->doctype)throw new RuntimeException('Invalid TPM source XML.');
    $xp=new DOMXPath($doc);$tpms=$xp->query('/domain/devices/tpm');
    if(!$tpms->length)return '';
    if($tpms->length!==1||$xp->evaluate('string(/domain/devices/tpm/backend/@type)')!=='emulator')throw new RuntimeException('Only a single software TPM is supported.');
    $candidates=array_map(fn($root)=>$root.'/'.$uuid,unmTpmRoots());
    foreach($xp->query('/domain/devices/tpm/backend/source/@path | /domain/devices/tpm/backend/@path') as $node) {
        $path=$node->value;
        if(!unmTpmPathAllowed($path,$uuid))throw new RuntimeException('Explicit TPM state path is outside verified UUID-scoped libvirt storage.');
        $candidates[]=$path;
    }
    $found=[];
    foreach(array_unique($candidates) as $path) {
        if(!file_exists($path)&&!is_link($path))continue;
        $real=realpath($path);
        if($real===false||!is_dir($real)||!unmTpmPathAllowed($real,$uuid))throw new RuntimeException('Unsafe software TPM state directory: '.$path);
        $found[$real]=true;
    }
    if(count($found)>1)throw new RuntimeException('Multiple distinct TPM state directories match this VM UUID.');
    if(!$found&&$required)throw new RuntimeException('Software TPM state was not found in verified libvirt storage.');
    return $found?(string)array_key_first($found):'';
}
function unmOwnedNvram(array $vm): array {
    $maps=[];
    foreach($vm['disks']??[] as $disk)if(($disk['type']??'')==='file'){
        $path=(string)($disk['resolvedSource']??$disk['source']??'');
        $maps[]=['source'=>$path,'destination'=>$path,'bundled'=>false];
    }
    $source=(string)($vm['nvram']??'');
    return unmMigrationNvramPlan($vm,$maps,['features'=>['customNvramMigration'=>true],'storage'=>['imageDirectory'=>dirname(dirname($source))]]);
}
function unmHostStateNoNestedMounts(string $path,?string $mountInfo=null): void {
    $mountInfo??=@file_get_contents('/proc/self/mountinfo');
    if($mountInfo===false||$mountInfo==='')throw new RuntimeException('Unable to verify host-state mount boundaries.');
    foreach(preg_split('/\R/',$mountInfo) as $line){
        if($line==='')continue;
        $fields=explode(' ',$line);
        if(count($fields)<6)throw new RuntimeException('Invalid host-state mount inventory.');
        $mount=preg_replace_callback('/\\\\([0-7]{3})/',static fn($m)=>chr(octdec($m[1])),$fields[4]);
        if(str_starts_with($mount,rtrim($path,'/').'/'))throw new RuntimeException('Nested mount in host-state directory; cleanup is blocked.');
    }
}
function unmHostStateEvidence(string $path,string $kind,string $uuid): array {
    if(!preg_match('/^[a-f0-9-]{36}$/i',$uuid))throw new RuntimeException('Invalid host-state VM UUID.');
    $cleanupAllowed=true;
    if($kind==='tpm'){
        if(!unmTpmPathAllowed($path,$uuid)||realpath($path)!==$path)throw new RuntimeException('Unsafe TPM cleanup evidence path.');
    }elseif($kind==='nvram'){
        $cleanupAllowed=unmRecoveryStateDestinationAllowed($path,'nvram',$uuid)||unmNvramPoolPath($path);
        // Existing libvirt variables files need not have UUID filenames. They
        // remain transferable, but collecting evidence must not authorize deletion.
        $legacy=(str_starts_with($path,'/etc/libvirt/qemu/nvram/')||str_starts_with($path,'/var/lib/libvirt/qemu/nvram/'))&&!preg_match('~[\x00-\x1f\x7f]|//|/(?:\.|\.\.)(?:/|$)~',$path);
        if(!$cleanupAllowed&&!$legacy)throw new RuntimeException('Unsafe NVRAM cleanup evidence path.');
        unmNvramRegularFile($path,unmNvramPoolPath($path));
        if($cleanupAllowed&&!unmNvramPoolPath($path)){
            $resolved=realpath($path);$contained=false;
            foreach(['/etc/libvirt/qemu/nvram','/var/lib/libvirt/qemu/nvram'] as $root){
                $realRoot=realpath($root);
                if(unmPathWithin($path,$root)&&$realRoot!==false&&$resolved!==false&&unmPathWithin($resolved,$realRoot))$contained=true;
            }
            if(!$contained)throw new RuntimeException('NVRAM evidence escapes verified libvirt storage.');
        }
    }else throw new RuntimeException('Unknown host-state kind.');
    unmHostStateNoNestedMounts($path);
    $rootStat=lstat($path);if($rootStat===false)throw new RuntimeException('Missing host-state evidence root.');
    $entries=[];
    $walk=function(string $file,string $relative)use(&$walk,&$entries,$rootStat):void{
        clearstatcache(true,$file);
        $s=lstat($file);
        if($s===false||is_link($file)||(!is_file($file)&&!is_dir($file)))throw new RuntimeException('Unsafe entry in host-state evidence: '.$file);
        if($s['dev']!==$rootStat['dev'])throw new RuntimeException('Host-state evidence crosses a filesystem boundary.');
        if(is_file($file)&&(int)$s['nlink']!==1)throw new RuntimeException('Hard-linked host-state file is not cleanup-safe.');
        $entries[$relative]=['type'=>is_dir($file)?'directory':'file','device'=>$s['dev'],'inode'=>$s['ino'],'sha256'=>is_file($file)?hash_file('sha256',$file):null];
        if(is_dir($file)){
            $names=scandir($file);if($names===false)throw new RuntimeException('Unable to inventory host-state directory.');
            foreach($names as $name)if($name!=='.'&&$name!=='..')$walk($file.'/'.$name,ltrim($relative.'/'.$name,'/'));
        }
    };
    $walk($path,'');ksort($entries);
    return ['kind'=>$kind,'path'=>$path,'resolved'=>realpath($path),'vmUuid'=>$uuid,'cleanupAllowed'=>$cleanupAllowed,'entries'=>$entries];
}
function unmVerifyHostStateEvidence(array $evidence): void {
    $current=unmHostStateEvidence((string)$evidence['path'],(string)$evidence['kind'],(string)$evidence['vmUuid']);
    if($current!==$evidence)throw new RuntimeException('Host-state contents or ownership changed; cleanup is blocked: '.$evidence['path']);
}
function unmValidateHostStateManifest(array $evidence,string $uuid,string $nvram,string $tpm): void {
    $expected=[];if($nvram!=='')$expected['nvram']=$nvram;if($tpm!=='')$expected['tpm']=$tpm;$seen=[];
    foreach($evidence as $item){
        $kind=$item['kind']??'';
        if(isset($seen[$kind])||!isset($expected[$kind])||($item['path']??'')!==$expected[$kind]||($item['vmUuid']??'')!==$uuid)throw new RuntimeException('Cleanup state evidence does not match the exact VM manifest.');
        if(empty($item['cleanupAllowed']))throw new RuntimeException('Legacy NVRAM filename is not UUID-scoped; retain it for manual cleanup.');
        if($kind==='nvram'&&unmNvramPoolPath($item['path']))unmNvramOtherVmReferences($uuid,dirname($item['path']));
        unmVerifyHostStateEvidence($item);$seen[$kind]=true;
    }
    if(count($seen)!==count($expected))throw new RuntimeException('Legacy or incomplete host-state cleanup evidence; retain the state for manual inspection.');
}
function unmRemoveHostStateEvidence(array $evidence): void {
    if(empty($evidence['cleanupAllowed']))throw new RuntimeException('Host-state evidence does not authorize deletion.');
    unmVerifyHostStateEvidence($evidence);
    $entries=$evidence['entries'];uksort($entries,static fn($a,$b)=>strlen($b)<=>strlen($a));
    foreach($entries as $relative=>$entry){
        $path=$evidence['path'].($relative!==''?'/'.$relative:'');
        $stat=lstat($path);
        if($stat===false||is_link($path)||$stat['ino']!==$entry['inode']||$stat['dev']!==$entry['device'])throw new RuntimeException('Host-state entry changed during cleanup.');
        if($entry['type']==='file'){if(!hash_equals($entry['sha256'],(string)hash_file('sha256',$path))||!unlink($path))throw new RuntimeException('Unable to remove verified host-state file.');}
        elseif(!rmdir($path))throw new RuntimeException('Unable to remove exact host-state directory.');
    }
}
