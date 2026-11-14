<?php
// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
declare(strict_types=1);

// Provenance for cleanup/return transfers, NOT native start authority. A normal
// owner has no entry in live-authority/live-managed and no peer start dependency.
function unmLiveNormalPath(string $uuid,string $base=UNM_BOOT_DIR): string {return $base.'/live-normal/'.unmTransportUuid($uuid).'.json';}
function unmLiveNormalRead(string $uuid,string $base=UNM_BOOT_DIR): ?array {
    $path=unmLiveNormalPath($uuid,$base);if(!file_exists($path)&&!is_link($path))return null;
    if(is_link($path)||is_link(dirname($path)))throw new RuntimeException('Normal-owner completion receipt is redirected.');$r=unmNativeJson($path);
    if(($r['schemaVersion']??null)!==1||($r['vmUuid']??'')!==$uuid||!in_array($r['phase']??'',['RELEASING','NORMAL'],true)||!in_array($r['kind']??'',['live','ordinary'],true)||!preg_match('/\A[a-f0-9]{32}\z/D',(string)($r['nonce']??''))||!is_array($r['authority']??null)||($r['authority']['vmUuid']??'')!==$uuid||($r['authority']['state']??'')!=='OWNER'||($r['authority']['jobId']??'')!==($r['authorityJobId']??null))throw new RuntimeException('Normal-owner completion receipt is corrupt.');
    foreach(['authorityHash','markerHash'] as $key)if(!preg_match('/\A[a-f0-9]{64}\z/D',(string)($r[$key]??'')))throw new RuntimeException('Invalid normal-owner identity hash.');
    foreach(['ownerHostId','peerHostId'] as $key)unmTransportUuid((string)($r[$key]??''));
    if($r['ownerHostId']===$r['peerHostId'])throw new RuntimeException('Normal completion cannot belong to a self pairing.');
    if($r['kind']==='live')unmLiveId((string)($r['jobId']??''));else unmTransportId((string)($r['jobId']??''));return $r;
}
function unmLiveNormalCurrent(string $uuid): ?array {
    $r=unmLiveNormalRead($uuid);if($r===null)return null;
    if($r['ownerHostId']!==unmHostId())throw new RuntimeException('Normal-owner receipt belongs to another host.');
    if($r['phase']!=='NORMAL'||unmLiveNativeRecord(UNM_BOOT_DIR,$uuid)!==null)return null;return $r;
}
/** Cleanup evidence only; never a restriction on native starts or VM editing. */
function unmLiveNormalStorageEvidence(string $xml): array {
    $d=unmNativeDomain($xml);$xp=$d['xpath'];$shape=[];$objects=[];
    foreach($xp->query('/domain/devices/disk[@device="disk"]|/domain/os/nvram|/domain/devices/tpm') as $node)$shape[]=unmXmlCanonical($node);
    $definition=unmLiveCleanupHash($shape);
    try{
        foreach($xp->query('/domain/devices/disk[@device="disk"]') as $disk){
            $type=$disk->getAttribute('type');$path=$xp->evaluate($type==='block'?'string(source/@dev)':'string(source/@file)',$disk);
            if($path===''||!in_array($type,['file','block'],true))throw new RuntimeException('Unproven normal-owner disk source.');
            if(str_starts_with($path,'/mnt/user/')){$resolved=unmResolveUserSharePath($path);$physical=$resolved['physicalPath']??'';if($physical===''||!empty($resolved['ambiguous']))throw new RuntimeException('Normal-owner user-share path is not uniquely resolved.');$path=$physical;}
            if($type==='block'){$objects[$path]=['kind'=>'zvol','identity'=>unmLiveZvolInspect($path)];}
            else $objects[$path]=['kind'=>'file','identity'=>unmLiveCleanupFileIdentity($path)];
        }
        $nv=trim($xp->evaluate('string(/domain/os/nvram)'));if($nv!=='')$objects[$nv]=['kind'=>'nvram','identity'=>unmLiveCleanupFileIdentity($nv)];
        if($xp->query('/domain/devices/tpm')->length){$path=unmTpmStatePath($d['uuid'],$xml);$objects[$path]=['kind'=>'tpm','identity'=>unmLiveCleanupFileIdentity($path,true)];}
        ksort($objects);return ['definition'=>$definition,'objects'=>$objects];
    }catch(Throwable $e){return ['definition'=>$definition,'unproven'=>$e->getMessage()];}
}
/** Delete only the exact old OWNER records, under the same lock as native starts.
 * A synced intent precedes either unlink. A crash between them fails closed;
 * retry can remove only those hashes, never a newer migration's authority.
 */
function unmLiveNormalRelease(array $authority,string $kind,string $id,string $peerHostId,array $proof): array {
    if(!in_array($kind,['live','ordinary'],true))throw new RuntimeException('Invalid completion kind.');if($kind==='live')unmLiveId($id);else unmTransportId($id);unmTransportUuid($peerHostId);
    $uuid=unmTransportUuid($authority['vmUuid']);$base=UNM_BOOT_DIR;$pointer=unmLiveNormalPath($uuid);$paths=[$base.'/live-authority/'.$uuid.'.json',$base.'/live-managed/'.$uuid.'.json'];
    // No libvirt command while holding the native-hook lock: libvirt may itself
    // be waiting for a synchronous hook. Capture this local evidence beforehand.
    $storage=unmLiveNormalStorageEvidence(unmLiveCommand(['virsh','dumpxml',$uuid,'--inactive','--security-info']));
    $lock=unmNativeLock('live-'.$uuid);
    try{
        $old=unmLiveNormalRead($uuid);
        if($old!==null&&$old['ownerHostId']!==unmHostId())throw new RuntimeException('A foreign normal-owner receipt cannot release local authority.');
        if($old!==null&&$old['jobId']===$id&&$old['kind']===$kind&&$old['phase']==='NORMAL'){
            if($old['peerHostId']!==$peerHostId)throw new RuntimeException('Normal completion belongs to another peer.');
            foreach($paths as $p)if(file_exists($p)||is_link($p))throw new RuntimeException('A later ownership cycle superseded this completion.');return $old;
        }
        if(($authority['state']??'')!=='OWNER'||($authority['workflow']??'')==='ordinary-pending'||$authority['vmUuid']!==$uuid||($authority[$authority['role'].'HostId']??'')!==unmHostId())throw new RuntimeException('Only the exact settled local owner can become a normal VM.');
        if($old!==null&&$old['phase']==='RELEASING'){
            if($old['jobId']!==$id||$old['kind']!==$kind||$old['authority']!==$authority||$old['peerHostId']!==$peerHostId)throw new RuntimeException('Another normal completion is pending.');$receipt=$old;
        }else{
            if(unmLiveNativeRecord($base,$uuid)!==$authority)throw new RuntimeException('Owner authority changed before normal completion.');
            $receipt=['schemaVersion'=>1,'kind'=>$kind,'jobId'=>$id,'authorityJobId'=>$authority['jobId'],'vmUuid'=>$uuid,'ownerHostId'=>unmHostId(),'peerHostId'=>$peerHostId,'phase'=>'RELEASING','nonce'=>bin2hex(random_bytes(16)),'authority'=>$authority,'authorityHash'=>hash_file('sha256',$paths[0]),'markerHash'=>hash_file('sha256',$paths[1]),'peerProof'=>$proof,'storageEvidence'=>$storage,'createdAt'=>gmdate('c')];
            unmLivePrivateDir(dirname($pointer));unmNativeWrite($pointer,$receipt);unmLiveCommand(['sync','-f',$pointer]);
        }
        foreach($paths as $i=>$path)if((file_exists($path)||is_link($path))&&(is_link($path)||!is_file($path)||!hash_equals($receipt[$i===0?'authorityHash':'markerHash'],(string)hash_file('sha256',$path))))throw new RuntimeException('Newer or replaced authority cannot be removed by an old completion.');
        $admission='/run/unmotion/lifecycle/live-admissions/'.$uuid.'.json';
        if(is_file($admission)){$a=unmNativeJson($admission);if(($a['jobId']??'')!==$authority['jobId']||in_array($a['phase']??'',['INCOMING','PREPARED','STARTING'],true))throw new RuntimeException('A different or in-flight native admission superseded this completion.');}
        foreach($paths as $path)if(is_file($path)&&!unlink($path))throw new RuntimeException('Cannot finish removing the exact obsolete owner fence; retry completion.');
        foreach($paths as $path)unmLiveCommand(['sync','-f',dirname($path)]);
        $receipt['phase']='NORMAL';$receipt['completedAt']=gmdate('c');unmNativeWrite($pointer,$receipt);unmLiveCommand(['sync','-f',$pointer]);
        if($kind==='live'){$history=dirname(unmLiveJobPath($id)).'/normal-owner.json';unmNativeWrite($history,$receipt);unmLiveCommand(['sync','-f',$history]);}
        if(is_file($admission)&&!unlink($admission))error_log('Completed VM is normal; obsolete runtime admission could not be removed: '.$uuid);
        unmInventoryCache()->invalidate();return $receipt;
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
function unmLiveNormalPeerProof(array $j,array $remote): void {
    $expected=$j['role']==='destination'?'RETIRED':'ABORTED';
    if(($remote['jobId']??'')!==$j['id']||($remote['vmUuid']??'')!==$j['vmUuid']||($remote['hostId']??'')!==$j[$j['role']==='destination'?'sourceHostId':'destinationHostId']||($remote['authority']??'')!==$expected||($remote['vm']['qemuAbsent']??null)!==true||!in_array($remote['vm']['state']??'',['undefined','shut off'],true))throw new RuntimeException('Normal completion needs fresh exact retired/aborted peer absence, not a missing peer or guessed owner.');
}
/** Caller owns the ordinary VM-operation lock. No stop/start/resume is issued. */
function unmLiveNormalComplete(array $j,?array $peerProof=null): array {
    $uuid=$j['vmUuid'];$current=unmLiveNormalRead($uuid);
    if($current!==null&&$current['ownerHostId']===unmHostId()&&$current['jobId']===$j['id']&&$current['phase']==='NORMAL'&&unmLiveNativeRecord(UNM_BOOT_DIR,$uuid)===null)return $current;
    if(!in_array($j['state'],['COMPLETE','FAILED','CANCELLED'],true)||$j['role']==='destination'&&$j['state']!=='COMPLETE')throw new RuntimeException('An unfinished migration cannot release owner restrictions.');
    $r=$current!==null&&$current['phase']==='RELEASING'?$current['authority']:unmLiveNativeRecord(UNM_BOOT_DIR,$uuid);
    if(($r['state']??'')!=='OWNER'||($r['jobId']??'')!==$j['id']||($r['workflow']??'')==='ordinary-pending'||unmNativeRecordForVm(UNM_BOOT_DIR,$uuid)!==null)throw new RuntimeException('Normal completion has no exact settled live owner.');
    unmLiveCleanupInterlock($uuid);unmLiveFenceProof();$state=unmLiveVmState($uuid);
    if(!in_array($state['state'],['running','idle','paused','shut off'],true))throw new RuntimeException('Normal completion cannot prove the owning VM.');unmLiveNoJob($uuid,$state);
    $xml=unmLiveCommand(['virsh','dumpxml',$uuid,'--inactive','--security-info']);$domain=unmNativeDomain($xml);unmLiveNativeBinding($domain,$r);
    if(!$state['qemuAbsent']){$q=unmNativeQemu($uuid,$domain['name']);$a=unmNativeJson('/run/unmotion/lifecycle/live-admissions/'.$uuid.'.json');if(($a['jobId']??'')!==$j['id']||($a['phase']??'')!=='RUNNING'||($a['bootId']??'')!==unmNativeBoot()||($a['qemu']??null)!==$q)throw new RuntimeException('Normal completion cannot adopt an unproven running QEMU.');}
    $peer=unmLivePeerForJob($j);unmLiveNormalRequirePeer($peer);$proof=$peerProof??unmLiveRpc($peer,'status',['jobId'=>$j['id']]);unmLiveNormalPeerProof($j,$proof);
    if(isset($j['sourceAutostart'])){if(!is_bool($j['sourceAutostart']))throw new RuntimeException('Invalid original autostart selection.');$args=['virsh','autostart',$uuid];if(!$j['sourceAutostart'])$args[]='--disable';unmLiveCommand($args);}
    $result=unmLiveNormalRelease($r,'live',$j['id'],$peer['hostId'],array_intersect_key($proof,array_flip(['jobId','vmUuid','hostId','authority','bootId','vm'])));
    $fresh=unmLiveJob($j['id']);unset($fresh['normalCompletionError'],$fresh['normalRetryAt']);$fresh['normalOwner']=true;unmLiveStoreJob($fresh);return $result;
}
function unmLiveNormalCleanupProof(array $j): ?array {
    $r=unmLiveNormalCurrent($j['vmUuid']);if($r===null)return null;
    if(unmNativeRecordForVm(UNM_BOOT_DIR,$j['vmUuid'])!==null)throw new RuntimeException('Recovery owns this VM; cleanup awaits an explicit authority proof.');
    $s=unmLiveVmState($j['vmUuid']);if(!in_array($s['state'],['running','idle','shut off'],true))throw new RuntimeException('The normal owning VM is absent or uncertain.');unmLiveNoJob($j['vmUuid'],$s);
    $xml=unmLiveCommand(['virsh','dumpxml',$j['vmUuid'],'--inactive','--security-info']);$d=unmNativeDomain($xml);if($d['uuid']!==$j['vmUuid'])throw new RuntimeException('Normal owner UUID changed.');
    $storage=unmLiveNormalStorageEvidence($xml);if(!isset($r['storageEvidence'])||isset($r['storageEvidence']['unproven'])||isset($storage['unproven'])||$storage!==$r['storageEvidence'])throw new RuntimeException('Normal-owner storage changed or is unproven since completion; retained-copy cleanup requires review. Ordinary VM controls remain available.');
    return ['hostId'=>unmHostId(),'bootId'=>unmNativeBoot(),'vmUuid'=>$j['vmUuid'],'authorityHash'=>unmLiveCleanupHash($r),'ownerJobId'=>$r['authorityJobId'],'normalOwner'=>true];
}
function unmLiveNormalRequirePeer(array $peer): void {
    $out=unmRemote($peer,'/usr/local/sbin/unmotion-agent capabilities',20);$caps=$out['code']===0?json_decode($out['stdout'],true):null;
    if(($caps['hostId']??'')!==$peer['hostId']||($caps['protocolVersion']??null)!==7||($caps['liveNormalVersion']??null)!==1)throw new RuntimeException('Normal live completion/return transfers require beta6 or later on both paired hosts.');
}
function unmLiveNormalQueue(string $id): void {
    $j=unmLiveJob($id);if(!in_array($j['state'],['COMPLETE','FAILED','CANCELLED'],true))return;$path=dirname(unmLiveJobPath($id)).'/finish-process.json';if(unmProcessRecordedWorker($path,'/usr/local/sbin/unmotion-live-finish',[$id]))return;
    $r=unmRun('nohup setsid /usr/local/sbin/unmotion-live-finish '.escapeshellarg($id).' >>'.escapeshellarg(dirname($path).'/finish.log').' 2>&1 </dev/null &',null,5);if($r['code']!==0)throw new RuntimeException('Completion worker could not start.');
}
function unmLiveNormalFinish(string $id): void {
    $j=unmLiveJob($id);$until=microtime(true)+8;do{$worker=unmProcessRecordedWorker(dirname(unmLiveJobPath($id)).'/process.json','/usr/local/sbin/unmotion-live-worker',[$id]);if(!$worker)break;usleep(100000);}while(microtime(true)<$until);
    if($worker)throw new RuntimeException('Live worker still active; completion will retry.');$lock=unmLiveVmLock($j['vmUuid']);
    try{
        $j=unmLiveJob($id);$r=unmLiveNormalRead($j['vmUuid']);$a=$r!==null&&$r['phase']==='RELEASING'?$r['authority']:unmLiveNativeRecord(UNM_BOOT_DIR,$j['vmUuid']);
        if(($a['state']??'')==='OWNER'&&($a['jobId']??'')===$id)unmLiveNormalComplete($j);
    }finally{flock($lock,LOCK_UN);fclose($lock);}
    // Automatic deletion is an explicit new-job contract, not retroactive
    // adoption of older/unrecorded artifacts or disposal of failed receivers.
    $j=unmLiveJob($id);if($j['role']!=='source'||$j['state']!=='COMPLETE'||($j['normalCompletionVersion']??null)!==1||isset($j['automaticCleanupError']))return;
    $cleanup=unmLiveCleanupRecord($id);if($cleanup!==null)return; // BLOCKED requires a visible reviewed retry.
    $view=unmLiveCleanupPreview($id);if($view['retained'])throw new RuntimeException('Automatic cleanup retained unproven objects; review required.');
    if($view['items'])unmLiveCleanupStart($id,$view['review']);
}
function unmLiveNormalScan(): void {
    foreach(glob(unmLiveRoot().'/jobs/*/job.json')?:[] as $path)try{
        $j=unmLiveJob(basename(dirname($path)));if(!in_array($j['state'],['COMPLETE','FAILED','CANCELLED'],true))continue;
        $pointer=unmLiveNormalRead($j['vmUuid']);$r=$pointer!==null&&$pointer['phase']==='RELEASING'?$pointer['authority']:unmLiveNativeRecord(UNM_BOOT_DIR,$j['vmUuid']);
        $owner=($r['state']??'')==='OWNER'&&($r['jobId']??'')===$j['id'];
        $cleanup=$j['role']==='source'&&$j['state']==='COMPLETE'&&($j['normalCompletionVersion']??null)===1&&!isset($j['automaticCleanupError'])&&unmLiveCleanupRecord($j['id'])===null;
        if(($owner&&(!isset($j['normalCompletionError'])||time()>=(int)($j['normalRetryAt']??PHP_INT_MAX)))||$cleanup)unmLiveNormalQueue($j['id']);
    }catch(Throwable $e){error_log('Live completion scan retained uncertain records: '.$e->getMessage());}
}
