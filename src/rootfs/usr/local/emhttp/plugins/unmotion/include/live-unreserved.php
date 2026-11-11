<?php
// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
declare(strict_types=1);

/** Absence is not ownership: an existing owner/receiver or recovery record blocks abandonment. */
function unmLiveUnreservedGuard(?array $authority,array $vm,?array $recovery): void {
    if($recovery!==null||($vm['state']??'')!=='undefined'||($vm['qemuAbsent']??false)!==true||$authority!==null&&!in_array($authority['state']??'',['RETIRED','ABORTED'],true))throw new RuntimeException('An unreserved receiver cannot be proven absent and non-owning.');
}
function unmLiveUnreservedProof(array $proof,array $request,array $peer,string $nonce): void {
    if(($proof['hostId']??'')!==$peer['hostId']||($proof['role']??'')!=='source'||($proof['jobId']??'')!==$request['jobId']||($proof['vmUuid']??'')!==$request['vmUuid']||($proof['authority']??'')!=='MOVING'||($proof['nonce']??'')!==$nonce||!hash_equals(hash('sha256',$request['token']),(string)($proof['tokenHash']??'')))throw new RuntimeException('Fresh source proof does not authorize abandoning this unreserved attempt.');
}
/** Journal a non-startable tombstone, never a storage or execution reservation. */
function unmLiveAbandonUnreserved(array $peer,array $a,bool $manual=false): array {
    $id=unmLiveId((string)$a['jobId']);$uuid=unmTransportUuid((string)$a['vmUuid']);$token=(string)$a['token'];
    if(!preg_match('/\A[a-f0-9]{64}\z/D',$token)||$manual&&!preg_match('/\A[a-f0-9]{32}\z/D',(string)($a['nonce']??'')))throw new RuntimeException('Invalid unreserved-attempt authorization.');
    $admission=unmTransportAdmissionLock();try{
        $lock=unmLiveVmLock($uuid);$path=unmLiveJobPath($id);$old=null;
        if(file_exists($path)||is_link($path)){$old=unmLivePeerJob($peer,$id,$manual?null:$token);if($old['role']!=='destination'||($old['unreserved']??false)!==true||$old['vmUuid']!==$uuid||($old['diskPath']??null)!==''||!in_array($old['state'],['ABANDONING','ABORTED'],true))throw new RuntimeException('A real recorded receiver must use normal authenticated abort/reconciliation.');}
        unmLiveUnreservedGuard(unmLiveNativeRecord(UNM_BOOT_DIR,$uuid),unmLiveVmState($uuid),unmNativeRecordForVm(UNM_BOOT_DIR,$uuid));
        $nonce=$manual?$a['nonce']:bin2hex(random_bytes(16));$proof=unmLiveRpc($peer,$manual?'reconcile-proof':'source-proof',['jobId'=>$id,'nonce'=>$nonce]);unmLiveUnreservedProof($proof,$a,$peer,$nonce);
        unmLiveUnreservedGuard(unmLiveNativeRecord(UNM_BOOT_DIR,$uuid),unmLiveVmState($uuid),unmNativeRecordForVm(UNM_BOOT_DIR,$uuid));
        $name=$proof['vmName']??$uuid;if(!is_string($name)||$name===''||strlen($name)>128||preg_match('~[/\x00-\x1f\x7f]~',$name))throw new RuntimeException('Invalid unreserved VM name.');
        $j=['schemaVersion'=>1,'id'=>$id,'vmUuid'=>$uuid,'vmName'=>$name,'role'=>'destination','peerId'=>$peer['id'],'sourceHostId'=>$peer['hostId'],'destinationHostId'=>unmHostId(),'bootId'=>unmNativeBoot(),'expiresAt'=>time()+21600,'state'=>'ABANDONING','tokenHash'=>hash('sha256',$token),'runtime'=>unmLiveRuntime($id),'diskPath'=>'','unreserved'=>true,'createdAt'=>$old['createdAt']??gmdate('c'),'message'=>'Confirming an unreserved receiver is non-owning; partial data is retained.'];
        unmLiveStoreJob($j);unmLiveCommand(['sync','-f',unmLiveJobPath($id)]);unmLiveAuthority($j,'ABORTED','');$j['state']='ABORTED';$j['message']='No receiver reservation existed; target absence confirmed. Any untracked partial data is retained.';unmLiveStoreJob($j);unmLiveCommand(['sync','-f',unmLiveJobPath($id)]);return unmLiveStatus($j)+['notReserved'=>true];
    }finally{flock($admission,LOCK_UN);fclose($admission);}
}
