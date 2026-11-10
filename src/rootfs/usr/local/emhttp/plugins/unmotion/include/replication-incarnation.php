<?php
// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
declare(strict_types=1);

function unmReplicationCycleNonce(array $record): string {
    if(!array_key_exists('incarnation',$record))return '';
    $nonce=$record['incarnation'];
    if(!is_string($nonce)||!preg_match('/\A[a-f0-9]{32}\z/D',$nonce))throw new RuntimeException('Invalid replication incarnation.');
    return $nonce;
}

/** Serialize creation across all incarnations of one VM/destination pair. */
function unmReplicationPairLock(string $vmUuid,string $destinationHostId) {
    $path='/var/lock/unmotion-replication-pair-'.unmReplicationId($vmUuid,$destinationHostId).'.lock';$lock=fopen($path,'ce');
    if($lock===false)throw new RuntimeException('Cannot lock replication pair admission.');@chmod($path,0600);
    if(!flock($lock,LOCK_EX|LOCK_NB)){fclose($lock);throw new RuntimeException('Another replication configuration request is active for this VM/destination. Retry shortly.');}
    return $lock;
}

function unmReplicationPairPolicies(string $vmUuid,string $destinationHostId): array {
    $out=[];
    foreach(glob(UNM_REPLICATIONS_DIR.'/*/policy.json')?:[] as $path){
        $p=unmReplicationPolicy(basename(dirname($path)));
        if(strcasecmp($p['vmUuid'],$vmUuid)===0&&$p['peerHostId']===$destinationHostId)$out[]=$p;
    }
    return $out;
}

/** Pure selection of a current cycle; historical records are never overwritten. */
function unmReplicationSelectCycle(string $vmUuid,string $destinationHostId,bool $creating=false): array {
    $history=unmReplicationPairPolicies($vmUuid,$destinationHostId);$active=[];$retired=[];
    foreach($history as $p){
        $r=unmCleanupRead(unmRetirementPath($p['id']));
        if(!$r){$active[]=$p;continue;}
        $retired[]=[$p,$r];
    }
    if(count($active)>1)throw new RuntimeException('Multiple unfinished replication policies exist for this VM/destination. Reconcile them before configuring another cycle.');
    if($active){if($creating)throw new RuntimeException('A replication policy already exists for this VM and destination. Configure it or finish ending it first.');$p=$active[0];return ['id'=>$p['id']]+(isset($p['incarnation'])?['incarnation'=>unmReplicationCycleNonce($p)]:[]);}
    foreach($retired as [$p,$r])unmReplicationAssertCycleEnded($p,$r);
    if(!$history)return ['id'=>unmReplicationId($vmUuid,$destinationHostId)];
    $nonce=bin2hex(random_bytes(16));
    return ['id'=>unmReplicationId($vmUuid,$destinationHostId,$nonce),'incarnation'=>$nonce];
}

function unmReplicationAssertCycleEnded(array $p,array $r): void {
    $identity=unmRetirementIdentity($p);
    foreach($identity as $k=>$v)if(($r[$k]??null)!==$v)throw new RuntimeException('Historical retirement identity is uncertain; reconcile it before creating another cycle.');
    $mode=($r['state']??'')==='ENDED_KEEP'?'keep':(($r['state']??'')==='ENDED_DELETE'?'delete':'');
    $ack=$r['remoteAck']??[];
    if($mode===''||($r['phase']??'')!=='COMPLETE'||($r['mode']??'')!==$mode||($ack['success']??null)!==true||!preg_match('/\A[a-f0-9]{32}\z/D',(string)($r['token']??''))||($ack['token']??null)!==$r['token']||($ack['mode']??null)!==$mode||($r['storageHash']??'')!==unmReplicationStorageFingerprint($p['storage'])||($ack['storageHash']??null)!==$r['storageHash']||!is_bool($ack['partialRetained']??null))throw new RuntimeException('Finish or retry the preceding replication retirement before creating another cycle.');
    if(unmProcessRecordedWorker(unmReplicationPath($p['id']).'/retirement-process.json','/usr/local/sbin/unmotion-retire-worker',[$p['id'],$r['token']])!==null)throw new RuntimeException('The preceding retirement worker is still finishing. Retry shortly.');
    unmRetirementAssertRecoveryIdle(unmRecoverySourcePath($p['id']));
}

/** A new cycle uses a sibling of the legacy VM directory, never a child of it. */
function unmReplicaStateDirectory(array $identity,?array $config=null): string {
    $config??=unmLoadConfig();$nonce=unmReplicationCycleNonce($identity);
    return rtrim((string)$config['image_dir'],'/').'/.unmotion-replicas/'.substr(hash('sha256',$identity['sourceHostId']),0,16).'/'.$identity['vmUuid'].($nonce!==''?'-'.$identity['replicationId']:'');
}

function unmReplicaAssertCycleEnded(array $manifest,array $receipt): void {
    foreach(['sourceHostId','destinationHostId','replicationId','vmUuid','vmName'] as $key)if(($receipt[$key]??null)!==($manifest[$key]??null))throw new RuntimeException('Prior replica retirement identity is uncertain.');
    if(unmReplicationCycleNonce($manifest)!==unmReplicationCycleNonce($receipt)||($manifest['replicationId']??'')!==unmReplicationId((string)$manifest['vmUuid'],(string)$manifest['destinationHostId'],unmReplicationCycleNonce($manifest)))throw new RuntimeException('Prior replica incarnation identity is uncertain.');
    $mode=($receipt['state']??'')==='KEPT'?'keep':(($receipt['state']??'')==='DELETED'?'delete':'');$ack=$receipt['ack']??[];
    if($mode===''||($manifest['state']??'')!==($mode==='keep'?'RETIRED':'DELETED')||($receipt['mode']??null)!==$mode||($ack['success']??null)!==true||!preg_match('/\A[a-f0-9]{32}\z/D',(string)($receipt['token']??''))||($ack['token']??null)!==$receipt['token']||($ack['mode']??null)!==$mode||($ack['storageHash']??'')!==unmReplicationStorageFingerprint((array)$manifest['storage'])||!is_bool($ack['partialRetained']??null))throw new RuntimeException('Destination still has an unfinished or unconfirmed prior replication cycle. Finish its retirement before creating another.');
}

/** Fresh destination admission, not a conclusion drawn only from a source ACK. */
function unmReplicaAssertPriorCyclesEnded(array $identity,string $currentDir): void {
    foreach(glob(UNM_REPLICAS_DIR.'/*/*/manifest.json')?:[] as $path){
        if($path===$currentDir.'/manifest.json')continue;$m=unmCleanupRead($path);
        if(($m['sourceHostId']??null)!==$identity['sourceHostId']||($m['vmUuid']??null)!==$identity['vmUuid'])continue;
        if(($m['destinationHostId']??null)!==$identity['destinationHostId']||basename(dirname($path))!==($m['replicationId']??null))throw new RuntimeException('Ambiguous prior replica identity blocks a new cycle.');
        unmReplicaAssertCycleEnded($m,unmCleanupRead(dirname($path).'/retirement.json'));
        unmRetirementAssertRecoveryIdle(dirname($path).'/recovery.json');
    }
}
