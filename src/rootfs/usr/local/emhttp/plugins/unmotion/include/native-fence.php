<?php
declare(strict_types=1);
// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
// Deliberately standalone: hooks MUST NOT call libvirt, SSH, or the API library.

function unmNativeJson(string $path): array {
    $value=json_decode((string)@file_get_contents($path),true);
    if(!is_array($value))throw new RuntimeException('Recovery fencing record is unreadable: '.basename($path));
    return $value;
}
function unmNativeWrite(string $path,array $value): void {
    $dir=dirname($path);if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('Cannot create native fencing runtime directory.');
    $tmp=tempnam($dir,'.native-');if($tmp===false)throw new RuntimeException('Cannot create native fencing runtime file.');
    try{$data=json_encode($value,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";if(file_put_contents($tmp,$data,LOCK_EX)!==strlen($data)||!chmod($tmp,0600)||!rename($tmp,$path))throw new RuntimeException('Cannot commit native fencing runtime record.');}finally{if(is_file($tmp))@unlink($tmp);}
}
function unmNativeBoot(): string {
    $boot=strtolower(trim((string)@file_get_contents('/proc/sys/kernel/random/boot_id')));if(!preg_match('/^[a-f0-9-]{36}$/',$boot))throw new RuntimeException('Host boot identity is unavailable.');return $boot;
}
function unmNativeDaemon(int $pid): array {
    $exe=(string)@readlink('/proc/'.$pid.'/exe');$cmd=explode("\0",rtrim((string)@file_get_contents('/proc/'.$pid.'/cmdline'),"\0"));
    if($pid<2||!in_array($exe,['/usr/sbin/libvirtd','/usr/sbin/virtqemud','/usr/bin/libvirtd','/usr/bin/virtqemud'],true)||basename((string)($cmd[0]??''))!==basename($exe))throw new RuntimeException('The native hook caller is not a verified libvirt daemon.');
    $stat=(string)@file_get_contents('/proc/'.$pid.'/stat');$end=strrpos($stat,')');$fields=$end===false?[]:preg_split('/\s+/',trim(substr($stat,$end+1)));$ticks=(string)($fields[19]??'');if(!ctype_digit($ticks))throw new RuntimeException('Libvirt process lifetime is unavailable.');
    return ['pid'=>$pid,'startTicks'=>$ticks,'exe'=>$exe,'bootId'=>unmNativeBoot()];
}
function unmNativeProofStatus(string $run='/run/unmotion/lifecycle'): array {
    try{$proof=unmNativeJson($run.'/native-hook-proof.json');$daemon=unmNativeDaemon((int)($proof['daemon']['pid']??0));
        if($daemon!==($proof['daemon']??null)||!is_executable('/etc/libvirt/hooks/qemu.d/50-unmotion-recovery')||!hash_equals((string)($proof['hookSha256']??''),(string)@hash_file('sha256','/etc/libvirt/hooks/qemu.d/50-unmotion-recovery'))||!hash_equals((string)($proof['helperSha256']??''),(string)@hash_file('sha256',__FILE__)))throw new RuntimeException('Native recovery hook has not been proven active for this libvirt process and installed build.');
        return ['ready'=>true,'daemon'=>$daemon];
    }catch(Throwable $e){return ['ready'=>false,'reason'=>$e->getMessage().' Verify the native hook; a libvirt restart may be required before arming recovery.'];}
}
function unmNativeDomain(string $xml): array {
    if(strlen($xml)>2097152||stripos($xml,'<!DOCTYPE')!==false||stripos($xml,'<!ENTITY')!==false)throw new RuntimeException('Invalid native hook domain XML.');
    $dom=new DOMDocument();$previous=libxml_use_internal_errors(true);try{$ok=$dom->loadXML($xml,LIBXML_NONET);}finally{libxml_clear_errors();libxml_use_internal_errors($previous);}
    if(!$ok||$dom->documentElement?->tagName!=='domain')throw new RuntimeException('Native hook domain XML is unreadable.');
    $xp=new DOMXPath($dom);$uuid=strtolower(trim($xp->evaluate('string(/domain/uuid)')));if(!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/',$uuid)||$xp->query('/domain/uuid')->length!==1)throw new RuntimeException('Native hook domain UUID is invalid.');
    $xp->registerNamespace('um','urn:unmotion:recovery:1');$nodes=$xp->query('/domain/metadata/um:activation');$activation=$nodes->length===1?$nodes->item(0):null;
    return ['uuid'=>$uuid,'name'=>trim($xp->evaluate('string(/domain/name)')),'dom'=>$dom,'xpath'=>$xp,'activationId'=>$activation?trim($activation->textContent):'','replicationId'=>$activation?$activation->getAttribute('replicationId'):'','activationCount'=>$nodes->length];
}
function unmNativeRecordForVm(string $base,string $uuid): ?array {
    $matches=[];$seen=[];
    foreach(array_merge(glob($base.'/replications/*/recovery.json')?:[],glob($base.'/replicas/*/*/recovery.json')?:[]) as $path){
        $raw=json_decode((string)@file_get_contents($path),true);$side=str_contains($path,'/replications/')?'source':'destination';
        $fallbackPath=dirname($path).($side==='source'?'/policy.json':'/manifest.json');$fallback=json_decode((string)@file_get_contents($fallbackPath),true);
        $candidate=strtolower((string)($raw['vmUuid']??''));$fallbackUuid=strtolower((string)($fallback['vmUuid']??''));
        if($candidate===''&&$fallbackUuid==='')throw new RuntimeException('Recovery inventory has an unreadable VM identity.');
        foreach([$candidate,$fallbackUuid] as $identity)if($identity!==''&&!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/',$identity))throw new RuntimeException('Recovery inventory has a malformed VM identity.');
        if($candidate!==''&&$fallbackUuid!==''&&$candidate!==$fallbackUuid)throw new RuntimeException('Recovery record and policy VM identities disagree.');
        if(($candidate?:$fallbackUuid)!==$uuid)continue;
        if(!is_array($raw))throw new RuntimeException('This VM has corrupt recovery authority.');
        $id=basename(dirname($path));if(!preg_match('/^repl-[a-f0-9]{24}$/',$id)||(string)($raw['replicationId']??'')!==$id||(string)($raw['side']??'')!==$side||(int)($raw['schemaVersion']??0)!==1||(int)($raw['protocolVersion']??0)!==6||(int)($raw['recoveryProtocolVersion']??0)!==1||!is_int($raw['term']??null)||$raw['term']<0)throw new RuntimeException('This VM has invalid recovery authority identity.');
        $seen[$side.':'.$id]=true;
        if(in_array((string)($raw['state']??''),['REPLICATION_ONLY','DISARMED','REMOVED'],true)&&empty($raw['armed'])&&empty($raw['managedAutostart'])&&empty($raw['activationId'])&&empty($raw['claim'])&&empty($raw['activation']))continue;
        if($raw['term']<1)throw new RuntimeException('Managed recovery authority has no positive term.');
        $matches[]=['record'=>$raw,'path'=>$path,'id'=>$id,'side'=>$side,'policy'=>$fallback];
    }
    $marker=$base.'/native-managed/'.$uuid.'.json';if(is_file($marker)){$m=unmNativeJson($marker);if(($m['schemaVersion']??null)!==1||($m['vmUuid']??'')!==$uuid||!isset($seen[(string)($m['side']??'').':'.(string)($m['replicationId']??'')]))throw new RuntimeException('Exact managed VM recovery authority is missing; start remains fenced.');}
    if(count($matches)>1)throw new RuntimeException('More than one recovery authority record claims this VM.');return $matches[0]??null;
}
function unmNativeAssertRemovable(string $base='/boot/config/plugins/unmotion'): void {
    foreach(array_merge(glob($base.'/replications/*/recovery.json')?:[],glob($base.'/replicas/*/*/recovery.json')?:[]) as $path){$r=unmNativeJson($path);if(!in_array((string)($r['state']??''),['REPLICATION_ONLY','DISARMED','REMOVED'],true)||!empty($r['armed'])||!empty($r['managedAutostart'])||!empty($r['activationId'])||!empty($r['activation'])||!empty($r['claim']))throw new RuntimeException('Disarm and reconcile every recovery policy before removing unMotion; native VM fencing must remain installed.');}
    foreach(glob($base.'/native-managed/*.json')?:[] as $path){$m=unmNativeJson($path);$uuid=(string)($m['vmUuid']??'');if(!preg_match('/^[a-f0-9-]{36}$/',$uuid)||unmNativeRecordForVm($base,$uuid)!==null)throw new RuntimeException('Native managed authority must be reconciled before removal.');}
}
function unmNativeMarkManaged(string $uuid,string $id,string $side,string $base='/boot/config/plugins/unmotion'): void {
    if(!preg_match('/^[a-f0-9-]{36}$/',$uuid)||!preg_match('/^repl-[a-f0-9]{24}$/',$id)||!in_array($side,['source','destination'],true))throw new RuntimeException('Invalid durable native fencing identity.');
    unmNativeWrite($base.'/native-managed/'.$uuid.'.json',['schemaVersion'=>1,'vmUuid'=>$uuid,'replicationId'=>$id,'side'=>$side]);
}
function unmNativeQemu(string $uuid,string $name): array {
    if($name===''||str_contains($name,'/')||str_contains($name,"\0"))throw new RuntimeException('Invalid native QEMU identity.');
    $pid=(int)trim((string)@file_get_contents('/run/libvirt/qemu/'.$name.'.pid'));$exe=(string)@readlink('/proc/'.$pid.'/exe');$args=explode("\0",rtrim((string)@file_get_contents('/proc/'.$pid.'/cmdline'),"\0"));$index=array_search('-uuid',$args,true);
    if($pid<2||!str_starts_with(basename($exe),'qemu-system-')||$index===false||strtolower((string)($args[$index+1]??''))!==$uuid)throw new RuntimeException('Cannot prove the admitted QEMU process identity.');
    $stat=(string)@file_get_contents('/proc/'.$pid.'/stat');$end=strrpos($stat,')');$fields=$end===false?[]:preg_split('/\s+/',trim(substr($stat,$end+1)));$ticks=(string)($fields[19]??'');if(!ctype_digit($ticks))throw new RuntimeException('QEMU process lifetime is unavailable.');
    return ['pid'=>$pid,'startTicks'=>$ticks,'exe'=>$exe,'bootId'=>unmNativeBoot(),'uuid'=>$uuid];
}
function unmNativeLock(string $id,string $locks='/var/lock'): mixed {
    $handle=fopen($locks.'/unmotion-recovery-'.$id.'.lock','c');if($handle===false)throw new RuntimeException('Native recovery lock is unavailable.');$until=microtime(true)+2;
    do{if(flock($handle,LOCK_EX|LOCK_NB))return $handle;usleep(20000);}while(microtime(true)<$until);fclose($handle);throw new RuntimeException('Recovery authority is busy; retry the managed start.');
}
function unmNativeHoldActive(array $r,int $now): bool {
    $h=(array)($r['hold']??[]);if(!$h||!empty($h['releasedAt']))return false;$until=(string)($h['holdUntil']??'');return $until==='forever'||($until!==''&&(($time=strtotime($until))===false||$time>$now));
}
function unmNativeCommon(array $c,string $uuid,string $base,string $boot): void {
    $r=$c['record'];$host=trim((string)@file_get_contents($base.'/host_id'));
    if(strtolower((string)$r['vmUuid'])!==$uuid||empty($r['armed'])||$host===''||$host!==(string)($r[$c['side'].'HostId']??''))throw new RuntimeException('Local recovery authority identity does not match this VM.');
    if(strtolower((string)($r[$c['side'].'BootId']??''))!==$boot)throw new RuntimeException('This host boot has no reconciled recovery authority.');
}
function unmNativeSourcePermit(array $c,string $uuid,string $base,string $run,string $boot,int $now): array {
    $r=$c['record'];$p=$c['policy'];$id=$c['id'];unmNativeCommon($c,$uuid,$base,$boot);
    if(!is_array($p)||(string)($p['id']??'')!==$id||strtolower((string)($p['vmUuid']??''))!==$uuid||(string)($p['peerHostId']??'')!==(string)$r['destinationHostId']||empty($r['managedAutostart'])||(string)$r['state']!=='STANDBY'||(string)$r['authority']!=='SOURCE'||unmNativeHoldActive($r,$now))throw new RuntimeException('Source recovery authority is not startable; use unMotion recovery controls.');
    if(!is_file($run.'/start-permits/'.$id.'.json'))throw new RuntimeException('No fresh one-shot source start permit; use unMotion Start source VM.');
    $p=unmNativeJson($run.'/start-permits/'.$id.'.json');
    foreach(['replicationId'=>$id,'vmUuid'=>$uuid,'sourceHostId'=>$r['sourceHostId'],'destinationHostId'=>$r['destinationHostId'],'sourceBootId'=>$boot,'requestId'=>$r['startAuthorization']['requestId']??''] as $key=>$value)if($value===''||(string)($p[$key]??'')!==(string)$value)throw new RuntimeException('The one-shot source start permit has a different authority identity.');
    $expires=strtotime((string)($p['expiresAt']??''));if(($p['schemaVersion']??null)!==1||(int)($p['term']??0)!==(int)$r['term']||$expires===false||$expires<=$now||$expires>$now+30)throw new RuntimeException('A fresh one-shot managed source start permit is required.');return $p;
}
function unmNativeDestinationBinding(array $c,array $domain,string $base,string $boot,int $now): array {
    $r=$c['record'];unmNativeCommon($c,$domain['uuid'],$base,$boot);$a=(array)($r['activation']??[]);$claim=(array)($r['claim']??[]);$grant=(array)($r['startGrant']??[]);
    if((string)$r['authority']!=='DESTINATION'||!in_array((string)$r['state'],['RECOVERED_STOPPED','RECOVERY_BOOT_FAILED','ACTIVATING'],true)||(string)($a['phase']??'')!=='STARTING'||$domain['activationCount']!==1||$domain['replicationId']!==$c['id']||$domain['activationId']===''||$domain['activationId']!==(string)($r['activationId']??'')||$domain['activationId']!==(string)($a['activationId']??'')||($claim['kind']??'')!=='coordinated')throw new RuntimeException('Recovered VM starts require an exact managed activation transaction.');
    $selection=(string)($claim['selectionHash']??'');$expires=strtotime((string)($grant['expiresAt']??''));if(!preg_match('/^[a-f0-9]{64}$/',$selection)||$selection!==(string)($a['selectionHash']??'')||$selection!==(string)($grant['selectionHash']??'')||($grant['sourceHostId']??'')!==$r['sourceHostId']||$expires===false||$expires<=$now||$expires>$now+150)throw new RuntimeException('A fresh authenticated source-fence grant is required.');
    $expected=[];foreach((array)($a['objects']??[]) as $object)foreach((array)($object['mappings']??[]) as $target){if(!is_string($target)||$target===''||isset($expected[$target]))throw new RuntimeException('Activation storage binding is invalid.');$expected[$target]=true;}
    foreach($domain['xpath']->query('/domain/devices/disk') as $disk){$sources=(new DOMXPath($domain['dom']))->query('./source',$disk);if($disk->getAttribute('device')==='cdrom'){if($sources->length)throw new RuntimeException('Recovery CD-ROM media is not authorized.');continue;}if($disk->getAttribute('device')!=='disk'||$sources->length!==1)throw new RuntimeException('Recovery disk binding is incomplete.');$source=$sources->item(0);$target=$source->getAttribute($disk->getAttribute('type')==='block'?'dev':'file');if(!isset($expected[$target]))throw new RuntimeException('Recovery disk is outside the exact activation journal.');unset($expected[$target]);}
    if($expected)throw new RuntimeException('Activation XML is missing an authorized disk.');return ['schemaVersion'=>1,'replicationId'=>$c['id'],'vmUuid'=>$domain['uuid'],'side'=>'destination','bootId'=>$boot,'term'=>(int)$r['term'],'activationId'=>$domain['activationId'],'grantSha256'=>hash('sha256',json_encode($grant,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))];
}
function unmNativeIssueDestinationPermit(array $record,string $run='/run/unmotion/lifecycle'): void {
    $uuid=strtolower((string)($record['vmUuid']??''));if(!preg_match('/^[a-f0-9-]{36}$/',$uuid))throw new RuntimeException('Invalid destination permit UUID.');
    unmNativeWrite($run.'/destination-permits/'.$uuid.'.json',['schemaVersion'=>1,'replicationId'=>(string)$record['replicationId'],'vmUuid'=>$uuid,'side'=>'destination','bootId'=>unmNativeBoot(),'term'=>(int)$record['term'],'activationId'=>(string)$record['activationId'],'grantSha256'=>hash('sha256',json_encode((array)$record['startGrant'],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)),'nonce'=>bin2hex(random_bytes(24)),'expiresAt'=>time()+30]);
}
function unmNativeFence(string $operation,string $phase,string $xml,string $base='/boot/config/plugins/unmotion',string $run='/run/unmotion/lifecycle',string $locks='/var/lock',?string $testBoot=null): void {
    if(!in_array($operation,['prepare','start','started','restore','migrate','attach','reconnect','release'],true))return;
    $domain=unmNativeDomain($xml);$uuid=$domain['uuid'];$boot=$testBoot??unmNativeBoot();$admission=$run.'/native-admissions/'.$uuid.'.json';
    $context=unmNativeRecordForVm($base,$uuid);if($context===null){if($domain['activationCount']>0)throw new RuntimeException('Activation ownership exists without recovery authority.');return;}
    $id=$context['id'];$lock=unmNativeLock($id,$locks);
    try{$context=unmNativeRecordForVm($base,$uuid);if($context===null)throw new RuntimeException('Recovery authority changed during native admission.');$r=$context['record'];$marker=$run.'/state/'.$id.'.running-authorized';
        if($operation==='release'){@unlink($admission);@unlink($run.'/start-permits/'.$id.'.json');@unlink($run.'/destination-permits/'.$uuid.'.json');@unlink($marker);return;}
        if(is_file(dirname($run).'/shutdown-fence'))throw new RuntimeException('The host shutdown fence is active.');
        if($domain['name']===''||str_contains($domain['name'],'/'))throw new RuntimeException('Managed VM has an unsafe libvirt name.');
        if(is_link('/etc/libvirt/qemu/autostart/'.$domain['name'].'.xml')||file_exists('/etc/libvirt/qemu/autostart/'.$domain['name'].'.xml'))throw new RuntimeException('Native autostart must be disabled for recovery-managed VMs.');
        if($operation==='attach'||$operation==='migrate'||$operation==='restore')throw new RuntimeException('Recovery-managed VMs cannot be attached, live-migrated or restored outside unMotion.');
        if($operation==='reconnect'){
            unmNativeCommon($context,$uuid,$base,$boot);$a=unmNativeJson($admission);if(($a['bootId']??'')!==$boot||($a['vmUuid']??'')!==$uuid||(int)($a['term']??0)!==(int)$r['term']||($a['side']??'')!==$context['side']||($a['replicationId']??'')!==$id)throw new RuntimeException('Reconnecting VM has no same-boot native admission.');
            if(($a['stage']??'')!=='RUNNING'||($a['qemu']??null)!==unmNativeQemu($uuid,$domain['name']))throw new RuntimeException('Reconnecting VM is not the exact admitted QEMU process.');
            if($context['side']==='source'){if(($r['authority']??'')!=='SOURCE'||!in_array($r['state'],['STANDBY','HOLDOFF'],true))throw new RuntimeException('Reconnecting source is fenced.');}
            elseif(($r['authority']??'')!=='DESTINATION'||!in_array($r['state'],['RECOVERED_RUNNING','RECOVERED_STOPPED','ACTIVATING'],true)||($a['activationId']??'')!==($r['activationId']??''))throw new RuntimeException('Reconnecting activation is fenced.');return;
        }
        if($operation==='started'){
            unmNativeCommon($context,$uuid,$base,$boot);$a=unmNativeJson($admission);
            if(($a['stage']??'')!=='STARTING'||($a['bootId']??'')!==$boot||($a['vmUuid']??'')!==$uuid||(int)($a['term']??0)!==(int)$r['term']||($a['replicationId']??'')!==$id||($a['side']??'')!==$context['side']||(int)($a['expiresAt']??0)<=time())throw new RuntimeException('QEMU started without a current exact native admission.');
            if($context['side']==='source'&&(($r['authority']??'')!=='SOURCE'||($r['state']??'')!=='STANDBY'))throw new RuntimeException('Source authority changed during native start.');
            if($context['side']==='destination'&&(($r['authority']??'')!=='DESTINATION'||($a['activationId']??'')!==($r['activationId']??'')))throw new RuntimeException('Destination authority changed during native start.');
            $a['qemu']=unmNativeQemu($uuid,$domain['name']);$a['stage']='RUNNING';unset($a['expiresAt']);unmNativeWrite($admission,$a);
            if($context['side']==='source'){if(!is_dir(dirname($marker))&&!mkdir(dirname($marker),0700,true))throw new RuntimeException('Cannot record admitted source start.');$value=$boot.':'.$r['term'].':'.$uuid."\n";if(file_put_contents($marker,$value,LOCK_EX)!==strlen($value))throw new RuntimeException('Cannot record admitted source start.');chmod($marker,0600);}return;
        }
        if($phase!=='begin')throw new RuntimeException('Unsupported native start phase.');
        if($context['side']==='source'){$permit=unmNativeSourcePermit($context,$uuid,$base,$run,$boot,time());$permitPath=$run.'/start-permits/'.$id.'.json';$accepted=['schemaVersion'=>1,'replicationId'=>$id,'vmUuid'=>$uuid,'term'=>(int)$r['term'],'side'=>'source','bootId'=>$boot,'requestId'=>$permit['requestId']];}
        else{$accepted=unmNativeDestinationBinding($context,$domain,$base,$boot,time());$permitPath=$run.'/destination-permits/'.$uuid.'.json';if(!is_file($permitPath))throw new RuntimeException('No fresh one-shot replica start permit; use unMotion recovery controls.');$permit=unmNativeJson($permitPath);foreach($accepted as $key=>$value)if(($permit[$key]??null)!==$value)throw new RuntimeException('Destination one-shot permit binding does not match.');if(!preg_match('/^[a-f0-9]{48}$/',(string)($permit['nonce']??''))||(int)($permit['expiresAt']??0)<=time()||(int)$permit['expiresAt']>time()+30)throw new RuntimeException('Destination one-shot permit is stale.');$accepted['nonce']=$permit['nonce'];}
        if($operation==='prepare'){
            if(is_file($admission)){$old=unmNativeJson($admission);if(in_array((string)($old['stage']??''),['PREPARING','STARTING'],true)&&(int)($old['expiresAt']??0)>time())throw new RuntimeException('A native start is already in flight for this VM.');}
            unmNativeWrite($admission,$accepted+['stage'=>'PREPARING','expiresAt'=>time()+120]);
        }
        if($operation==='start'){
            $pending=unmNativeJson($admission);foreach($accepted as $key=>$value)if(($pending[$key]??null)!==$value)throw new RuntimeException('Native preparation identity changed before QEMU start.');
            if(($pending['stage']??'')!=='PREPARING'||(int)($pending['expiresAt']??0)<=time())throw new RuntimeException('Native start has no current preparation.');
            if(!unlink($permitPath))throw new RuntimeException('Cannot consume the native start permit.');unmNativeWrite($admission,$accepted+['stage'=>'STARTING','expiresAt'=>time()+120]);
        }
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
