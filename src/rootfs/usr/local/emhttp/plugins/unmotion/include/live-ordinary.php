<?php
// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
declare(strict_types=1);

/** Ordinary workflows retain a UUID ownership fence, not the live hardware contract. */
function unmLiveOrdinaryOwner(?array $r): bool {return $r!==null&&($r['workflow']??'')==='ordinary'&&$r['state']==='OWNER';}
/** A retired source may finish only its own recorded ordinary migration policy. */
function unmLiveOrdinaryResolutionCheck(string $uuid,string $id): bool {
    $r=unmLiveNativeRecord(UNM_BOOT_DIR,$uuid);if($r===null||unmLiveOrdinaryOwner($r))return false;
    $j=unmLiveOrdinaryJob($id);
    if(($r['workflow']??'')!=='ordinary'||$r['state']!=='RETIRED'||($r['transferId']??'')!==$id||$j['vmUuid']!==$uuid||($r['transferPeer']??'')!==unmPeer($j['peerId'])['hostId'])throw new RuntimeException('Only the exact retired ordinary handover can finish its source policy.');
    return true;
}
function unmLiveRecordReplace(array $r,?array $expected): array {
    $lock=unmNativeLock('live-'.$r['vmUuid']);
    try{
        $old=unmLiveNativeRecord(UNM_BOOT_DIR,$r['vmUuid']);
        if($old!==$expected)throw new RuntimeException('Live authority changed during the reviewed operation.');
        $admission='/run/unmotion/lifecycle/live-admissions/'.$r['vmUuid'].'.json';
        if(is_file($admission)){$a=unmNativeJson($admission);if(($a['bootId']??'')===unmNativeBoot()&&in_array($a['phase']??'',['INCOMING','PREPARED','STARTING'],true))throw new RuntimeException('An in-flight native start blocks this ownership change.');}
        $r['updatedAt']=gmdate('c');unmNativeWrite(UNM_BOOT_DIR.'/live-authority/'.$r['vmUuid'].'.json',$r);
        unmNativeWrite(UNM_BOOT_DIR.'/live-managed/'.$r['vmUuid'].'.json',['schemaVersion'=>1,'vmUuid'=>$r['vmUuid'],'jobId'=>$r['jobId']]);
        unmLiveCommand(['sync','-f',UNM_BOOT_DIR.'/live-authority/'.$r['vmUuid'].'.json']);unmLiveCommand(['sync','-f',UNM_BOOT_DIR.'/live-managed/'.$r['vmUuid'].'.json']);
        return $r;
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
function unmLiveHandbackReview(string $id): array {
    $j=unmLiveJob($id);$r=unmLiveNativeRecord(UNM_BOOT_DIR,$j['vmUuid']);$peer=unmLivePeerForJob($j);unmLiveFenceProof();
    unmLiveCleanupInterlock($j['vmUuid']);
    if(($r['jobId']??'')!==$id||!in_array($j['state'],['COMPLETE','FAILED','CANCELLED'],true)||!($r['state']==='OWNER'||$r['state']==='MOVING'&&($r['workflow']??'')==='ordinary-pending'))throw new RuntimeException('Only a settled local owner can return to ordinary workflows.');
    $vm=unmLiveVmState($j['vmUuid']);if($vm['state']!=='shut off'||!$vm['qemuAbsent'])throw new RuntimeException('Fully power off the owner before returning to ordinary workflows.');
    $xml=unmLiveCommand(['virsh','dumpxml',$j['vmUuid'],'--inactive','--security-info']);unmLiveNativeBinding(unmNativeDomain($xml),$r);
    if(unmActiveVmJob($j['vmUuid'],unmJobs())!==null||unmNativeRecordForVm(UNM_BOOT_DIR,$j['vmUuid'])!==null)throw new RuntimeException('Another migration or recovery owns this VM.');
    foreach(['seeds/*/seed.json','replications/*/policy.json'] as $pattern)foreach(glob(UNM_BOOT_DIR.'/'.$pattern)?:[] as $path)if((unmLoadJson($path)['vmUuid']??'')===$j['vmUuid'])throw new RuntimeException('Remove prepared moves/replication policies before hand-back.');
    $remote=unmLiveRpc($peer,'status',['jobId'=>$id]);
    if(!in_array($remote['authority']??'',['RETIRED','ABORTED'],true)||empty($remote['vm']['qemuAbsent'])||!in_array($remote['vm']['state']??'',['undefined','shut off'],true))throw new RuntimeException('The peer must prove its copy absent/stopped and durably retired/aborted.');
    $review=hash('sha256',json_encode([$r,unmLiveXmlHash($xml),$remote],JSON_THROW_ON_ERROR));
    return ['success'=>true,'id'=>$id,'vmUuid'=>$j['vmUuid'],'vmName'=>$j['vmName'],'review'=>$review,'alreadyOrdinary'=>unmLiveOrdinaryOwner($r)];
}
function unmLiveHandback(string $id,string $review): array {
    if(!preg_match('/\A[a-f0-9]{64}\z/D',$review))throw new RuntimeException('Invalid hand-back review.');
    $j=unmLiveJob($id);$lock=unmLiveVmLock($j['vmUuid']);$view=unmLiveHandbackReview($id);
    if(!hash_equals($review,$view['review']))throw new RuntimeException('VM or peer state changed; review hand-back again.');
    $r=unmLiveNativeRecord(UNM_BOOT_DIR,$j['vmUuid']);if(unmLiveOrdinaryOwner($r))return ['success'=>true];
    $pending=$r;$pending['workflow']='ordinary-pending';$pending['handbackNonce']=$r['handbackNonce']??bin2hex(random_bytes(16));unmLiveRecordReplace($pending,$r);
    // No native lock is held over the callback. MOVING prevents a concurrent native start.
    $reply=unmLiveRpc(unmLivePeerForJob($j),'handback-ack',['jobId'=>$id,'nonce'=>$pending['handbackNonce']]);
    if(($reply['nonce']??'')!==$pending['handbackNonce']||empty($reply['retired']))throw new RuntimeException('The peer did not acknowledge its retained start fence. Retry hand-back; do not remove authority files.');
    if(!unmLiveVmState($j['vmUuid'])['qemuAbsent'])throw new RuntimeException('Unexpected owner QEMU during hand-back.');
    $pending=unmLiveNativeRecord(UNM_BOOT_DIR,$j['vmUuid']);$owner=$pending;$owner['state']='OWNER';$owner['workflow']='ordinary';unmLiveRecordReplace($owner,$pending);
    unmInventoryCache()->invalidate();return ['success'=>true];
}
function unmLiveHandbackRemote(array $peer,array $a): array {
    if(array_diff(array_keys($a),['action','jobId','nonce'])||count($a)!==3||!preg_match('/\A[a-f0-9]{32}\z/D',(string)($a['nonce']??'')))throw new RuntimeException('Invalid hand-back request.');
    $j=unmLivePeerJob($peer,(string)$a['jobId']);$r=unmLiveNativeRecord(UNM_BOOT_DIR,$j['vmUuid']);$s=unmLiveStatus($j);unmLiveFenceProof();
    if(($r['jobId']??'')!==$j['id']||empty($s['vm']['qemuAbsent']))throw new RuntimeException('Hand-back identity or stopped-state proof failed.');
    if($a['action']==='handback-proof'){
        if($r['state']!=='OWNER'||($r['workflow']??'')!=='ordinary-pending'||($r['handbackNonce']??'')!==$a['nonce'])throw new RuntimeException('No matching pending owner hand-back.');
        return ['success'=>true,'nonce'=>$a['nonce'],'pending'=>true];
    }
    if($a['action']!=='handback-ack'||!in_array($r['state'],['RETIRED','ABORTED'],true))throw new RuntimeException('A non-owner fence is required.');
    $lock=unmLiveVmLock($j['vmUuid']);$proof=unmLiveRpc($peer,'handback-proof',['jobId'=>$j['id'],'nonce'=>$a['nonce']]);
    if(empty($proof['pending'])||($proof['nonce']??'')!==$a['nonce'])throw new RuntimeException('The owner did not prove its pending hand-back.');
    $next=$r;$next['workflow']='ordinary';$next['handbackNonce']=$a['nonce'];unmLiveRecordReplace($next,$r);
    return ['success'=>true,'nonce'=>$a['nonce'],'retired'=>true];
}

function unmLiveOrdinaryJob(string $id): array {
    unmTransportId($id);$path=UNM_JOBS_DIR.'/'.$id.'/job.json';if(is_link($path)||!is_file($path))throw new RuntimeException('Missing ordinary migration job.');
    $j=unmLoadJson($path);if(($j['id']??'')!==$id||($j['jobType']??'migration')!=='migration')throw new RuntimeException('Invalid ordinary migration identity.');
    // Released ordinary jobs stored the UUID only in request.cfg/job.request.
    // Normalize that exact identity without requiring old jobs to be rewritten.
    $uuid=unmTransportUuid((string)($j['vmUuid']??$j['request']['VM_UUID']??''));
    if(isset($j['request']['VM_UUID'])&&strtolower((string)$j['request']['VM_UUID'])!==$uuid)throw new RuntimeException('Ordinary migration VM identities differ.');
    $j['vmUuid']=$uuid;return $j;
}
function unmLiveOrdinaryCheck(array $j): ?array {
    unmLiveCleanupInterlock($j['vmUuid']);
    $r=unmLiveNativeRecord(UNM_BOOT_DIR,$j['vmUuid']);
    if($r===null){$normal=unmLiveNormalCurrent($j['vmUuid']);if($normal===null)return null;$r=$normal['authority'];$r['workflow']='ordinary';$r['normalReturn']=1;}
    if(!unmLiveOrdinaryOwner($r)&&!(($r['workflow']??'')==='ordinary'&&($r['transferId']??'')===$j['id']&&in_array($r['state'],['MOVING','RETIRED'],true)))throw new RuntimeException('Ordinary migration has no owning live hand-back.');
    $peer=unmPeer($j['peerId']);$caps=unmRemote($peer,'/usr/local/sbin/unmotion-agent capabilities',20);$c=$caps['code']===0?json_decode($caps['stdout'],true):null;
    if(($c['hostId']??'')!==$peer['hostId']||($c['liveOrdinaryVersion']??null)!==1)throw new RuntimeException('A previously live-migrated VM needs Beta5 or later on both peers for Cold/Warm Move.');
    if(($r['normalReturn']??null)===1&&($c['liveNormalVersion']??null)!==1)throw new RuntimeException('Normal-owner return transfers need beta6 or later on both hosts.');
    return $r;
}
/** Called under the ordinary worker's lifetime VM lock. No automatic ownership rollback after retirement. */
function unmLiveOrdinaryWorker(string $id,string $operation): bool {
    $j=unmLiveOrdinaryJob($id);$stored=unmLiveNativeRecord(UNM_BOOT_DIR,$j['vmUuid']);$r=$stored;
    if($r===null){$normal=unmLiveNormalCurrent($j['vmUuid']);if($normal===null)return false;$r=$normal['authority'];$r['workflow']='ordinary';$r['normalReturn']=1;}
    if($operation==='rollback'){
        if(($r['workflow']??'')==='ordinary'&&($r['transferId']??'')===$id&&$r['state']==='MOVING'){$next=$r;$next['state']='OWNER';$next=unmLiveRecordReplace($next,$r);if(($next['normalReturn']??null)===1)unmLiveNormalRelease($next,'ordinary',$id,(string)$next['transferPeer'],['unretiredOrdinaryRollback'=>true]);}
        return true;
    }
    unmLiveOrdinaryCheck($j);if($operation==='check')return true;
    $state=unmLiveVmState($j['vmUuid']);if(!in_array($state['state'],['shut off','undefined'],true)||!$state['qemuAbsent'])throw new RuntimeException('The ordinary handover source must be provably powered off.');
    $peer=unmPeer($j['peerId']);
    if($operation==='prepare'){
        if(!unmLiveOrdinaryOwner($r))throw new RuntimeException('Ordinary source is already fenced by an unfinished transfer.');
        $next=$r;$next['state']='MOVING';$next['role']='source';$next['sourceHostId']=unmHostId();$next['destinationHostId']=$peer['hostId'];$next['bootId']=unmNativeBoot();$next['expiresAt']=time()+21600;$next['transferId']=$id;$next['transferPeer']=$peer['hostId'];$next['transferNonce']=bin2hex(random_bytes(16));
        $j['ordinaryHandover']=true;unmAtomicJson(UNM_JOBS_DIR.'/'.$id.'/job.json',$j);unmLiveRecordReplace($next,$stored);return true;
    }
    if($operation==='accept'){
        if(($r['transferId']??'')!==$id||!in_array($r['state'],['MOVING','RETIRED'],true))throw new RuntimeException('No exact ordinary source handover.');
        $xml=UNM_JOBS_DIR.'/'.$id.'/destination.xml';if(is_link($xml)||!is_file($xml))throw new RuntimeException('Missing exact destination XML.');
        $next=$r;$next['state']='RETIRED';$next['transferHash']=unmLiveXmlHash((string)file_get_contents($xml));unmLiveRecordReplace($next,$r);
    }elseif($operation!=='commit')throw new RuntimeException('Invalid ordinary handover operation.');
    $r=unmLiveNativeRecord(UNM_BOOT_DIR,$j['vmUuid']);if($r['state']!=='RETIRED'||($r['transferId']??'')!==$id)throw new RuntimeException('The source is not retired for this exact migration.');
    $reply=unmLiveRpc($peer,'ordinary-'.$operation,['jobId'=>$id,'vmUuid'=>$j['vmUuid'],'nonce'=>$r['transferNonce']]);
    if(($reply['nonce']??'')!==$r['transferNonce'])throw new RuntimeException('Ordinary peer acknowledgement failed.');return true;
}
function unmLiveOrdinaryRemote(array $peer,array $a): array {
    if(array_diff(array_keys($a),['action','jobId','vmUuid','nonce'])||count($a)!==4||!preg_match('/\A[a-f0-9]{32}\z/D',(string)($a['nonce']??'')))throw new RuntimeException('Invalid ordinary handover fields.');
    $id=unmTransportId((string)$a['jobId']);$uuid=unmTransportUuid((string)$a['vmUuid']);$r=unmLiveNativeRecord(UNM_BOOT_DIR,$uuid);unmLiveFenceProof();
    if($a['action']==='ordinary-proof'){
        $j=unmLiveOrdinaryJob($id);
        if($j['vmUuid']!==$uuid||unmPeer($j['peerId'])['hostId']!==$peer['hostId']||($r['workflow']??'')!=='ordinary'||$r['state']!=='RETIRED'||($r['transferId']??'')!==$id||($r['transferPeer']??'')!==$peer['hostId']||($r['transferNonce']??'')!==$a['nonce']||!unmLiveVmState($uuid)['qemuAbsent'])throw new RuntimeException('No exact, durably retired ordinary source proof.');
        return ['success'=>true,'nonce'=>$a['nonce'],'retired'=>true,'xmlHash'=>$r['transferHash'],'normalReturn'=>($r['normalReturn']??null)===1];
    }
    if(!in_array($a['action'],['ordinary-accept','ordinary-commit'],true))throw new RuntimeException('Invalid ordinary handover action.');
    $lock=unmLiveVmLock($uuid);$reservation=unmLoadJson(unmTransportReservationPath($peer,$id));
    if(($reservation['vmUuid']??'')!==$uuid||($reservation['sourceHostId']??'')!==$peer['hostId'])throw new RuntimeException('Ordinary handover has no exact peer-owned transfer reservation.');
    $proof=unmLiveRpc($peer,'ordinary-proof',['jobId'=>$id,'vmUuid'=>$uuid,'nonce'=>$a['nonce']]);
    if(empty($proof['retired'])||($proof['nonce']??'')!==$a['nonce'])throw new RuntimeException('Ordinary source callback failed.');
    if(($proof['normalReturn']??false)===true){
        unmLiveNormalRequirePeer($peer);$normal=unmLiveNormalCurrent($uuid);
        if($r===null&&$normal!==null&&$normal['kind']==='ordinary'&&$normal['jobId']===$id&&$normal['peerHostId']===$peer['hostId']&&unmLiveVmState($uuid)['state']!=='undefined')return ['success'=>true,'nonce'=>$a['nonce'],'committed'=>true];
    }
    $state=unmLiveVmState($uuid);if(!$state['qemuAbsent'])throw new RuntimeException('Destination is not verifiably powered off.');
    if($a['action']==='ordinary-accept'){
        $xmlPath=UNM_BOOT_DIR.'/incoming/'.$id.'/destination.xml';if(is_link($xmlPath)||!is_file($xmlPath))throw new RuntimeException('Missing reserved destination XML.');$xml=(string)file_get_contents($xmlPath);
        if(unmLiveXmlHash($xml)!==($proof['xmlHash']??''))throw new RuntimeException('Destination XML differs from the exact retired source handover.');
        unmTransportValidateDomainXml($xml,$uuid,$peer);
        if($r&&($r['workflow']??'')==='ordinary'&&($r['transferId']??'')===$id&&($r['transferNonce']??'')===$a['nonce']&&in_array($r['state'],['RECEIVING','OWNER'],true)){
            if($r['state']==='OWNER'&&($state['state']!=='shut off'||($r['definedHash']??'')!==unmCpuConfigHash(unmLiveCommand(['virsh','dumpxml',$uuid,'--inactive','--security-info']))))throw new RuntimeException('The committed owner changed; it must not be overwritten by a stale retry.');
            return ['success'=>true,'nonce'=>$a['nonce'],'committed'=>$r['state']==='OWNER'];
        }
        if($state['state']!=='undefined'||$r&&!(in_array($r['state'],['RETIRED','ABORTED'],true)&&(($r['workflow']??'')==='ordinary'||($proof['normalReturn']??false)===true)))throw new RuntimeException('Destination must be absent with no conflicting ownership.');
        $next=['schemaVersion'=>1,'vmUuid'=>$uuid,'vmName'=>unmXmlValue($xml,'name'),'jobId'=>$r['jobId']??'live-'.substr(hash('sha256',$peer['hostId'].'|'.$id),0,24),'role'=>'destination','state'=>'RECEIVING','diskPath'=>'','sourceHostId'=>$peer['hostId'],'destinationHostId'=>unmHostId(),'bootId'=>unmNativeBoot(),'expiresAt'=>time()+21600,'workflow'=>'ordinary','transferId'=>$id,'transferNonce'=>$a['nonce'],'transferHash'=>$proof['xmlHash']];
        if(($proof['normalReturn']??false)===true)$next['normalReturn']=1;
        // Missing records are admissible only for a UUID with no native live marker.
        if($r===null){unmLivePrivateDir(UNM_BOOT_DIR.'/live-authority');unmLivePrivateDir(UNM_BOOT_DIR.'/live-managed');}
        unmLiveRecordReplace($next,$r);return ['success'=>true,'nonce'=>$a['nonce']];
    }
    if(($r['workflow']??'')!=='ordinary'||($r['transferId']??'')!==$id||($r['transferNonce']??'')!==$a['nonce']||!in_array($r['state'],['RECEIVING','OWNER'],true)||$state['state']!=='shut off'||($r['definedHash']??'')!==unmCpuConfigHash(unmLiveCommand(['virsh','dumpxml',$uuid,'--inactive','--security-info'])))throw new RuntimeException('Ordinary destination definition changed or was never admitted.');
    $next=$r;$next['state']='OWNER';$next=unmLiveRecordReplace($next,$r);
    if(($next['normalReturn']??null)===1)unmLiveNormalRelease($next,'ordinary',$id,$peer['hostId'],['retired'=>true,'nonce'=>$a['nonce'],'xmlHash'=>$proof['xmlHash']]);
    return ['success'=>true,'nonce'=>$a['nonce']];
}
/** Definition bytes are supplied on stdin, never reread by virsh after validation. */
function unmLiveOrdinaryDefine(array $peer,array $reservation,string $xml): ?array {
    $r=unmLiveNativeRecord(UNM_BOOT_DIR,$reservation['vmUuid']);
    if(($r['workflow']??'')!=='ordinary'||$r['state']!=='RECEIVING')return null;
    if(($r['transferId']??'')!==$reservation['id']||$r['sourceHostId']!==$peer['hostId']||($r['transferHash']??'')!==unmLiveXmlHash($xml))throw new RuntimeException('Definition is not the authorized ordinary transfer.');
    $result=unmRun(['virsh','define','/dev/stdin','--validate'],$xml,30);
    if($result['code']===0){$next=$r;$next['definedHash']=unmCpuConfigHash(unmLiveCommand(['virsh','dumpxml',$r['vmUuid'],'--inactive','--security-info']));unmLiveRecordReplace($next,$r);}
    return $result;
}
function unmLiveOrdinaryResume(string $id): array {
    $j=unmLiveOrdinaryJob($id);
    if(unmProcessRecordedWorker(UNM_JOBS_DIR.'/'.$id.'/process.json','/usr/local/sbin/unmotion-worker',[$id]))throw new RuntimeException('The ordinary migration worker is still active.');
    $lock=unmLiveVmLock($j['vmUuid']);$r=unmLiveNativeRecord(UNM_BOOT_DIR,$j['vmUuid']);
    if(($r['transferId']??'')!==$id||($r['workflow']??'')!=='ordinary'||$r['state']!=='RETIRED')throw new RuntimeException('No interrupted ordinary handover for this job.');
    $peer=unmPeer($j['peerId']);$reply=unmLiveRpc($peer,'ordinary-accept',['jobId'=>$id,'vmUuid'=>$j['vmUuid'],'nonce'=>$r['transferNonce']]);
    if(empty($reply['committed'])){
        $path=UNM_BOOT_DIR.'/incoming/'.$id.'/destination.xml';$defined=unmRemote($peer,'virsh define '.escapeshellarg($path),45);if($defined['code']!==0)throw new RuntimeException('Destination remains fenced: '.trim($defined['stderr']));
    }
    // OWNER may have been persisted just before a lost reply/crash interrupted
    // normal release. Commit is idempotent and must finish that release too.
    unmLiveOrdinaryWorker($id,'commit');
    return ['success'=>true,'message'=>'Destination ownership committed. It remains stopped. Start it on the destination when ready, then resolve the original migration to finish its selected source policy.'];
}
