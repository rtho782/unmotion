<?php
// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
declare(strict_types=1);

function unmRetirementPath(string $id): string {return unmReplicationPath($id).'/retirement.json';}
/** Display-only interruption detection. Never resume cleanup from an inventory read. */
function unmRetirementDisplay(string $id,array $r): array {
    $updated=strtotime((string)($r['updatedAt']??$r['createdAt']??''));
    if(($r['state']??'')==='ENDING'&&($updated===false||$updated<time()-15)&&unmProcessRecordedWorker(unmReplicationPath($id).'/retirement-process.json','/usr/local/sbin/unmotion-retire-worker',[$id,(string)($r['token']??'')])===null){
        $r['state']='BLOCKED';$r['error']='The retirement worker stopped or the host restarted. Scheduling remains stopped; retry to reconcile the durable receipts.';
    }
    return $r;
}
/** Short API admission, distinct from the transfer-wide policy lock. */
function unmRetirementAdmissionLock(string $id) {
    unmReplicationPath($id);$path='/var/lock/unmotion-replication-admission-'.$id.'.lock';$lock=fopen($path,'ce');
    if($lock===false)throw new RuntimeException('Cannot lock replication configuration admission.');@chmod($path,0600);
    if(!flock($lock,LOCK_EX|LOCK_NB)){fclose($lock);throw new RuntimeException('Another policy configuration/retirement request is active. Retry shortly.');}
    return $lock;
}
function unmRetirementAssertOpen(string $id): void {
    if(file_exists(unmRetirementPath($id)))throw new RuntimeException('This replication is ending or permanently ended. Use its retirement controls, not Enable or Run now.');
}
function unmRetirementAssertRecoveryIdle(string $path): void {
    if(!file_exists($path)){
        $id=basename(dirname($path));
        foreach(glob(UNM_BOOT_DIR.'/native-managed/*.json')?:[] as $marker)if((unmCleanupRead($marker)['replicationId']??'')===$id)throw new RuntimeException('A native managed marker has missing recovery authority. Reconcile it before retirement.');
        return;
    }
    $r=unmRecoveryLoad($path);
    if(!empty($r['armed'])||!empty($r['managedAutostart'])||!empty($r['activation'])||!empty($r['activationId'])||!empty($r['claim'])||!in_array($r['state']??'',['REPLICATION_ONLY','DISARMED','REMOVED'],true))throw new RuntimeException('Recovery is armed, active or unresolved. Reconcile/disarm it before retirement can complete; start fences were preserved.');
}
function unmRetirementRpc(array $peer,string $command,array $request): array {
    $reply=unmRemote($peer,'/usr/local/sbin/unmotion-agent '.$command.' '.escapeshellarg(base64_encode(json_encode($request,JSON_THROW_ON_ERROR))),180);
    if($reply['code']!==0)throw new RuntimeException('Paired retirement request failed: '.trim($reply['stderr']));
    $r=json_decode($reply['stdout'],true,128,JSON_THROW_ON_ERROR);
    if(!is_array($r)||($r['success']??null)!==true)throw new RuntimeException('Invalid paired retirement acknowledgement.');
    return $r;
}
function unmRetirementIdentity(array $p): array {
    return ['sourceHostId'=>unmHostId(),'destinationHostId'=>$p['peerHostId'],'replicationId'=>$p['id'],'vmUuid'=>$p['vmUuid'],'vmName'=>$p['vmName']]+(isset($p['incarnation'])?['incarnation'=>unmReplicationCycleNonce($p)]:[]);
}
function unmRetirementReview(string $id): array {
    $p=unmReplicationPolicy($id);$r=unmCleanupRead(unmRetirementPath($id));
    return ['id'=>$id,'vmName'=>$p['vmName'],'storage'=>$p['storage'],'retirement'=>$r,'pending'=>!empty(unmReplicationDurableState($id)['pending']),'review'=>unmCleanupHash([$p,$r['token']??null,$r['state']??null])];
}
function unmRetirementBegin(string $id,string $mode,bool $discardPartial,string $review): array {
    if(!in_array($mode,['keep','delete'],true)||($mode==='keep'&&$discardPartial))throw new InvalidArgumentException('Invalid retirement choice.');
    $admissionLock=unmRetirementAdmissionLock($id);$requestLock=unmRecoveryAcquireLock($id);
    try{
        $view=unmRetirementReview($id);if(!hash_equals($view['review'],$review))throw new RuntimeException('Policy changed. Review retirement again.');
        $old=$view['retirement'];$path=unmRetirementPath($id);
        if($old&&unmProcessRecordedWorker(unmReplicationPath($id).'/retirement-process.json','/usr/local/sbin/unmotion-retire-worker',[$id,(string)($old['token']??'')])!==null){if(($old['mode']??'')!==$mode)throw new RuntimeException('The preceding retirement worker is still finishing. Retry after it exits.');return $old;}
        if($old){
            if(($old['state']??'')==='ENDED_DELETE')return $old;
            if(($old['state']??'')==='ENDED_KEEP'&&$mode==='keep')return $old;
            $authorizePartial=($old['state']??'')==='BLOCKED'&&$mode==='delete'&&$old['mode']==='delete'&&$old['discardPartial']===false&&$discardPartial&&empty($old['remoteAck']);
            if(($old['state']??'')!=='ENDED_KEEP'&&($old['mode']!==$mode||$old['discardPartial']!==$discardPartial)&&!$authorizePartial)throw new RuntimeException('Retry the existing retirement with its original choices. A blocked pre-cleanup deletion can explicitly authorize partial-receive discard.');
            if($authorizePartial)$old['discardPartial']=true;
        }
        if(!$old||($old['state']??'')==='ENDED_KEEP'){
            $p=unmReplicationPolicy($id);$old=unmRetirementIdentity($p)+['schemaVersion'=>1,'token'=>bin2hex(random_bytes(16)),'mode'=>$mode,'discardPartial'=>$discardPartial,'storageHash'=>unmReplicationStorageFingerprint($p['storage']),'createdAt'=>gmdate('c')];
        }
        unset($old['error']);$old['state']='ENDING';$old['message']='Scheduling and new runs are stopped. Waiting for active work and paired retirement.';$old['updatedAt']=gmdate('c');
        unmCleanupStore($path,$old);
    }finally{unmRecoveryReleaseLock($requestLock);}
    $log='/var/log/unmotion/replication-'.$id.'-retirement.log';
    $launch=unmRun('nohup setsid /usr/local/sbin/unmotion-retire-worker '.escapeshellarg($id).' '.escapeshellarg($old['token']).' >>'.escapeshellarg($log).' 2>&1 & echo $!',null,10);
    if($launch['code']!==0||(int)trim($launch['stdout'])<2)throw new RuntimeException('Retirement intent saved and scheduling stopped, but the worker could not launch. Retry retirement.');
    return $old;
}
/** Callback is read-only: the source worker holds the policy/VM/recovery locks. */
function unmRetirementSourceProof(array $q): array {
    $id=(string)($q['replicationId']??'');$p=unmReplicationPolicy($id);$r=unmCleanupRead(unmRetirementPath($id));
    foreach(unmRetirementIdentity($p) as $key=>$value)if(($q[$key]??null)!==$value)throw new RuntimeException('Retirement callback identity mismatch.');
    if(($r['token']??'')!==($q['token']??null)||($r['mode']??'')!==($q['mode']??null)||($r['discardPartial']??null)!==($q['discardPartial']??null)||($r['state']??'')!=='ENDING'||($r['phase']??'')!=='REMOTE'||($r['storageHash']??'')!==unmReplicationStorageFingerprint($p['storage']))throw new RuntimeException('No matching in-flight source retirement.');
    if(unmProcessRecordedWorker(unmReplicationPath($id).'/retirement-process.json','/usr/local/sbin/unmotion-retire-worker',[$id,$r['token']])===null)throw new RuntimeException('Retirement worker lifetime is not current.');
    unmRetirementAssertRecoveryIdle(unmRecoverySourcePath($id));
    return ['success'=>true,'token'=>$r['token'],'mode'=>$r['mode'],'discardPartial'=>$r['discardPartial'],'storageHash'=>$r['storageHash'],'sourceSnapshotsHash'=>unmCleanupHash($r['sourceSnapshots'])];
}
function unmRetirementSourceRun(string $id,string $token): array {
    $r=unmCleanupRead(unmRetirementPath($id));if(($r['token']??'')!==$token)throw new RuntimeException('Retirement token changed.');
    if(in_array($r['state']??'',['ENDED_KEEP','ENDED_DELETE'],true))return $r;
    $policyLock=unmAcquireReplicationPolicyLock($id,true);$p=unmReplicationPolicy($id);$vmLock=unmRecoveryAcquireVmLock($p['vmUuid']);
    try{
        foreach(unmRetirementIdentity($p) as $key=>$value)if(($r[$key]??null)!==$value)throw new RuntimeException('Retirement intent no longer matches the exact source policy.');
        if(($r['schemaVersion']??null)!==1||!in_array($r['mode']??'',['keep','delete'],true)||!is_bool($r['discardPartial']??null)||($r['storageHash']??'')!==unmReplicationStorageFingerprint($p['storage']))throw new RuntimeException('Corrupt or changed retirement intent.');
        if(isset($r['remoteAck'])){
            $ack=$r['remoteAck'];if(!is_array($ack)||($ack['success']??null)!==true||($ack['token']??'')!==$token||($ack['mode']??'')!==$r['mode']||($ack['storageHash']??'')!==$r['storageHash']||!is_bool($ack['partialRetained']??null)||!is_array($r['sourceSnapshots']??null))throw new RuntimeException('Corrupt destination retirement receipt; source bases retained.');
        }
        $r['state']='ENDING';$r['phase']='PREFLIGHT';$r['updatedAt']=gmdate('c');unmCleanupStore(unmRetirementPath($id),$r);
        $p['enabled']=false;unmAtomicJson(unmReplicationPath($id).'/policy.json',$p);
        $peer=unmPeer($p['peerId']);if(($peer['hostId']??'')!==$p['peerHostId'])throw new RuntimeException('Retirement peer identity changed.');
        $caps=unmRemote($peer,'/usr/local/sbin/unmotion-agent capabilities',45);$cap=json_decode($caps['stdout'],true);
        if($caps['code']!==0||($cap['retirementVersion']??0)!==1||($cap['protocolVersion']??0)!==7)throw new RuntimeException('Both peers need Beta6 retirement capability. No storage cleanup was started.');
        $recovery=unmCleanupRead(unmRecoverySourcePath($id));
        if(!empty($recovery['armed']))unmRecoveryDisarm($id);
        $recoveryLock=unmRecoveryAcquireLock($id);
        try{
            unmRetirementAssertRecoveryIdle(unmRecoverySourcePath($id));
            $pattern='/\Aunmotion-rpl-'.substr(hash('sha256',$id),0,12).'-g[1-9][0-9]*\z/D';
            if(!isset($r['sourceSnapshots'])){
                $d=unmReplicationDurableState($id);$known=[];
                foreach((array)($d['baseGuids']??[]) as $src=>$guid)if(!empty($d['baseSnapshot']))$known[$src.'@'.$d['baseSnapshot']]=(string)$guid;
                foreach((array)($d['pending']['snapshotGuids']??[]) as $src=>$guid)if(!empty($d['pending']['snapshot']))$known[$src.'@'.$d['pending']['snapshot']]=(string)$guid;
                foreach((array)($d['sourceCleanupPending']??[]) as $item)if(!empty($item['guid']))$known[$item['dataset'].'@'.$item['snapshot']]=(string)$item['guid'];
                $r['sourceSnapshots']=unmCleanupSourceSnapshots($p['storage'],$pattern,'unmotion:replication:'.$id,$known);
            }
            $q=unmRetirementIdentity($p)+['token'=>$token,'mode'=>$r['mode'],'discardPartial'=>$r['discardPartial'],'storage'=>$p['storage'],'sourceSnapshots'=>$r['sourceSnapshots']];
            if(empty($r['remoteAck'])){
                $r['phase']='REMOTE';unmCleanupStore(unmRetirementPath($id),$r);
                $plan=unmRetirementRpc($peer,'replication-retire',$q+['phase'=>'review']);
                $ack=unmRetirementRpc($peer,'replication-retire',$q+['phase'=>'commit','review'=>$plan['review']]);
                if(($ack['token']??'')!==$token||($ack['mode']??'')!==$r['mode']||($ack['storageHash']??'')!==$r['storageHash']||!is_bool($ack['partialRetained']??null))throw new RuntimeException('Destination retirement acknowledgement changed identity.');
                $r['remoteAck']=$ack;$r['phase']='SOURCE_CLEANUP';unmCleanupStore(unmRetirementPath($id),$r);
            }
            if(empty($r['remoteAck']['partialRetained']))unmCleanupDeleteSourceSnapshots($r['sourceSnapshots']);
            else $r['sourceBasesRetained']=true;
            $r['state']=$r['mode']==='keep'?'ENDED_KEEP':'ENDED_DELETE';$r['phase']='COMPLETE';$r['message']=$r['mode']==='keep'?'Replication ended. Destination replicas remain inert and inventoried.':'Replication ended. Exact replica data removed; logs and retirement receipts retained.';
            unset($r['error']);$r['updatedAt']=gmdate('c');unmCleanupStore(unmRetirementPath($id),$r);return $r;
        }finally{unmRecoveryReleaseLock($recoveryLock);}
    }finally{unmRecoveryReleaseLock($vmLock);unmReleaseReplicationPolicyLock($policyLock);}
}

function unmReplicaRetirementArtifacts(array $manifest): array {
    $dir=(string)($manifest['stateDirectory']??'');if($dir==='')return [];
    unmTransportPath($dir);$expected=unmReplicaStateDirectory($manifest);
    if($dir!==$expected)throw new RuntimeException('Replica state directory no longer matches the configured reserved layout.');
    $files=[];if(!is_dir($dir))return [];
    foreach(scandir($dir)?:[] as $name){
        if($name==='.'||$name==='..')continue;
        $path=$dir.'/'.$name;
        $file=unmCleanupFilePlan($path);$owned=false;
        if(preg_match('/\Astate-([a-f0-9]{24})\.tar\.gz\z/D',$name,$match))$owned=hash_equals($match[1],substr((string)hash_file('sha256',$path),0,24));
        else foreach((array)($manifest['points']??[]) as $point)if(($point['sourceXmlPath']??'')===$path&&preg_match('/\A[a-f0-9]{64}\z/D',(string)($point['sourceXmlSha256']??'')))$owned=hash_equals($point['sourceXmlSha256'],(string)hash_file('sha256',$path));
        if(!$owned)throw new RuntimeException('Unrecognized or hash-mismatched replica state artifact retained: '.$name);
        foreach(glob(UNM_REPLICAS_DIR.'/*/*/manifest.json')?:[] as $other){$m=unmCleanupRead($other);if(($m['replicationId']??'')===$manifest['replicationId']&&($m['sourceHostId']??'')===$manifest['sourceHostId'])continue;if(($m['stateDirectory']??'')===$dir)throw new RuntimeException('Shared replica state directory blocks deletion.');}
        $files[]=$file;
    }
    return $files;
}
/** Called only through the peer-bound dispatcher, under the destination VM lock. */
function unmReplicaRetirement(array $q): array {
    $identity=unmValidateReplicaRequestIdentity($q);$id=$identity['replicationId'];$dir=unmReplicaPath($identity['sourceHostId'],$id);
    if(!preg_match('/\A[a-f0-9]{32}\z/D',(string)($q['token']??''))||!in_array($q['mode']??'',['keep','delete'],true)||!is_bool($q['discardPartial']??null)||!in_array($q['phase']??'',['review','commit'],true))throw new RuntimeException('Invalid replica retirement request.');
    $lock=unmRecoveryAcquireLock($id);
    try{
        unmAssertReplicaVmUuidUndefined($identity['vmUuid']);unmRetirementAssertRecoveryIdle($dir.'/recovery.json');
        $manifest=unmCleanupRead($dir.'/manifest.json');$journal=unmCleanupRead($dir.'/retirement.json');
        $storageHash=unmReplicationStorageFingerprint((array)$q['storage']);
        if($manifest){
            foreach(['sourceHostId','destinationHostId','vmUuid','replicationId'] as $k)if(($manifest[$k]??'')!==$identity[$k])throw new RuntimeException('Replica retirement manifest identity mismatch.');
            if(unmReplicationStorageFingerprint((array)$manifest['storage'])!==$storageHash)throw new RuntimeException('Replica storage differs from its source policy.');
            unmCleanupAssertUnreferenced($manifest['storage']);
        }
        if($journal&&$journal['token']!==$q['token']&&!($journal['state']==='KEPT'&&$q['mode']==='delete'))throw new RuntimeException('Another retirement owns this replica inventory.');
        $same=$journal&&$journal['token']===$q['token'];
        if($same&&($journal['mode']!==$q['mode']||$journal['discardPartial']!==$q['discardPartial']))throw new RuntimeException('Retirement retry changed its destructive intent.');
        if($same)$plan=$journal['plan'];
        else{
            $plan=['storage'=>[],'files'=>[],'partialRetained'=>false];
            $pattern='/\Aunmotion-rpl-'.substr(hash('sha256',$id),0,12).'-g[1-9][0-9]*\z/D';
            foreach((array)($manifest['storage']??$q['storage']) as $s){
                if(!$manifest){if(unmCleanupZfsRows((string)$s['destination']))throw new RuntimeException('Unreserved destination storage is not deletion authority.');continue;}
                if(unmCleanupZfsRows($s['destination']))unmVerifyReplicaDestinationInert($s);
                if($q['mode']==='delete'){
                    $known=[];foreach((array)($manifest['points']??[]) as $point)foreach((array)($point['storage']??[]) as $item)$known[$item['destination'].'@'.$item['snapshot']]=(string)($item['guid']??'');
                    foreach((array)($q['sourceSnapshots']??[]) as $name=>$item)if(str_starts_with($name,$s['source'].'@'))$known[$s['destination'].substr($name,strlen($s['source']))]=(string)$item['guid'];
                    $plan['storage'][]=unmCleanupZfsPlan($s,$pattern,'unmotion:replication:'.$id,$q['discardPartial'],$known);
                }
                elseif(unmCleanupZfsRows($s['destination'])){$t=unmReplicaZfsProperty($s['destination'],'receive_resume_token');if($t!==''&&$t!=='-')$plan['partialRetained']=true;}
            }
            if($manifest&&$q['mode']==='delete')$plan['files']=unmReplicaRetirementArtifacts($manifest);
        }
        $owned=[];foreach((array)($manifest['storage']??[]) as $s)$owned[$s['destination']]=$s['kind'];
        foreach((array)($plan['storage']??[]) as $s)if(($owned[$s['destination']]??null)!==$s['kind'])throw new RuntimeException('Cleanup journal has no matching manifest-owned storage.');
        foreach((array)($plan['files']??[]) as $f)if(dirname($f['path'])!==($manifest['stateDirectory']??null))throw new RuntimeException('Cleanup journal file is outside its exact replica state directory.');
        $review=unmCleanupHash([$identity,$q['token'],$q['mode'],$q['discardPartial'],$storageHash,$plan]);
        if($q['phase']==='review')return ['success'=>true,'review'=>$review,'plan'=>$plan];
        if(!hash_equals($review,(string)($q['review']??'')))throw new RuntimeException('Replica cleanup plan changed. Retry with a fresh review.');
        $peer=unmFindPeerByHostId($identity['sourceHostId']);
        $proof=unmRetirementRpc($peer,'retirement-source-proof',$identity+['token'=>$q['token'],'mode'=>$q['mode'],'discardPartial'=>$q['discardPartial']]);
        if(($proof['token']??'')!==$q['token']||($proof['mode']??'')!==$q['mode']||($proof['discardPartial']??null)!==$q['discardPartial']||($proof['storageHash']??'')!==$storageHash||($proof['sourceSnapshotsHash']??'')!==unmCleanupHash((array)($q['sourceSnapshots']??[])))throw new RuntimeException('Source retirement callback proof failed.');
        if($same&&in_array($journal['state'],['KEPT','DELETED'],true))return $journal['ack'];
        if(!$manifest){if(!is_dir($dir)&&!mkdir($dir,0700,true))throw new RuntimeException('Cannot store empty retirement receipt.');$manifest=$identity+['storage'=>$q['storage'],'points'=>[]];}
        $journal=$identity+['token'=>$q['token'],'mode'=>$q['mode'],'discardPartial'=>$q['discardPartial'],'state'=>'CLEANING','plan'=>$plan,'updatedAt'=>gmdate('c')];
        // This destination receipt fences future receive/publish/arm admission.
        unmCleanupStore($dir.'/retirement.json',$journal);
        $guard=static function()use($dir,$manifest):void{unmRetirementAssertRecoveryIdle($dir.'/recovery.json');unmAssertReplicaVmUuidUndefined($manifest['vmUuid']);unmCleanupAssertUnreferenced($manifest['storage']);foreach($manifest['storage'] as $s)if(unmCleanupZfsRows($s['destination']))unmVerifyReplicaDestinationInert($s);};
        if($q['mode']==='delete'){
            $guard();unmCleanupValidateBatch($plan);
            foreach($plan['storage'] as $s)unmCleanupDeleteZfs($s,$guard);
            foreach($plan['files'] as $file){$guard();unmCleanupDeleteFile($file);}
            $manifest['points']=[];$manifest['currentPointId']=null;
        }
        $manifest['state']=$q['mode']==='keep'?'RETIRED':'DELETED';$manifest['retiredAt']=gmdate('c');unmCleanupStore($dir.'/manifest.json',$manifest);
        $journal['state']=$q['mode']==='keep'?'KEPT':'DELETED';$journal['ack']=['success'=>true,'token'=>$q['token'],'mode'=>$q['mode'],'storageHash'=>$storageHash,'partialRetained'=>$plan['partialRetained']];unmCleanupStore($dir.'/retirement.json',$journal);
        return $journal['ack'];
    }finally{unmRecoveryReleaseLock($lock);}
}
