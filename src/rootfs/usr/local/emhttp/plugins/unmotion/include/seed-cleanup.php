<?php
// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
declare(strict_types=1);

function unmSeedCleanupRemote(array $peer,array $q): array {
    $id=unmTransportId((string)($q['seedId']??''));
    if(!preg_match('/\Aseed-[a-f0-9]{24}\z/D',$id)||!preg_match('/\A[a-f0-9]{32}\z/D',(string)($q['token']??''))||!in_array($q['phase']??'',['review','commit'],true)||($q['discardPartial']??null)!==true)throw new RuntimeException('Invalid prepared-copy cleanup request.');
    unmAssertReplicaVmUuidUndefined($q['vmUuid']);
    $path=unmTransportReservationPath($peer,$id);$reservation=unmCleanupRead($path);$clean=$reservation;unset($clean['cleanupToken']);
    $receiptDir=UNM_BOOT_DIR.'/cleanup-receipts/'.$peer['id'];unmTransportPath($receiptDir);
    $receipt=$receiptDir.'/'.$id.'-'.$q['token'].'.json';$journal=unmCleanupRead($receipt);
    if($journal&&($journal['vmUuid']!==$q['vmUuid']||$journal['sourceHostId']!==$peer['hostId']))throw new RuntimeException('Cleanup receipt identity mismatch.');
    if($reservation){
        unmCleanupAssertUnreferenced((array)$reservation['storage']);
        if(($reservation['id']??'')!==$id||($reservation['vmUuid']??'')!==$q['vmUuid']||($reservation['sourceHostId']??'')!==$peer['hostId']||($reservation['peerId']??'')!==$peer['id'])throw new RuntimeException('Prepared-copy reservation identity mismatch.');
        if(isset($reservation['cleanupToken'])&&$reservation['cleanupToken']!==$q['token'])throw new RuntimeException('Another cleanup transaction owns this reservation.');
        if($journal&&unmCleanupHash($clean)!==$journal['reservationHash'])throw new RuntimeException('A new reservation replaced this transfer. Old cleanup cannot affect it.');
    }elseif(!$journal||$journal['state']!=='DELETED')throw new RuntimeException('Missing authenticated transfer reservation. Legacy storage was retained; no source snapshots should be released.');
    if($journal)$plan=$journal['plan'];
    else{
        $plan=['storage'=>[],'files'=>[]];$pattern='/\Aunmotion-seed-'.preg_quote($id,'/').'-g[1-9][0-9]*\z/D';
        foreach((array)$reservation['storage'] as $s){
            if(in_array($s['kind'],['dataset','zvol'],true)){
                $known=(array)($q['expectedSnapshotGuids']??[]);
                $plan['storage'][]=unmCleanupZfsPlan($s,$pattern,'unmotion:'.$id,true,$known);
            }
            elseif($s['kind']==='image')$plan['files'][]=unmCleanupFilePlan($s['destination']);
            else throw new RuntimeException('Unexpected prepared-copy storage kind.');
        }
        foreach((array)($reservation['files']??[]) as $file)$plan['files'][]=unmCleanupFilePlan($file);
        $inventory=UNM_BOOT_DIR.'/incoming/seeds/'.$id.'.json';$m=unmCleanupRead($inventory);
        if($m){if(($m['vmUuid']??'')!==$q['vmUuid'])throw new RuntimeException('Prepared inventory VM identity mismatch.');$plan['files'][]=unmCleanupFilePlan($inventory);}
    }
    if($reservation){$owned=[];foreach($reservation['storage'] as $s)$owned[$s['destination']]=$s['kind'];foreach((array)$plan['storage'] as $s)if(($owned[$s['destination']]??null)!==$s['kind'])throw new RuntimeException('Cleanup journal storage differs from its reservation.');}
    $reservationHash=$journal['reservationHash']??unmCleanupHash($clean);$review=unmCleanupHash([$q['vmUuid'],$q['token'],$reservationHash,$plan]);
    if($q['phase']==='review')return ['success'=>true,'review'=>$review,'plan'=>$plan,'reservationHash'=>$reservationHash];
    if(!hash_equals($review,(string)($q['review']??'')))throw new RuntimeException('Prepared cleanup plan changed; retry review.');
    if($journal&&$journal['state']==='DELETED'){
        if($reservation&&!unlink($path))throw new RuntimeException('Unable to clear completed transfer reservation.');
        return $journal['ack'];
    }
    if(!is_dir($receiptDir)&&!mkdir($receiptDir,0700,true))throw new RuntimeException('Unable to store cleanup receipt.');
    $journal=['schemaVersion'=>1,'seedId'=>$id,'vmUuid'=>$q['vmUuid'],'sourceHostId'=>$peer['hostId'],'token'=>$q['token'],'reservationHash'=>$reservationHash,'plan'=>$plan,'state'=>'CLEANING'];
    unmCleanupStore($receipt,$journal);$reservation['cleanupToken']=$q['token'];unmCleanupStore($path,$reservation);
    $guard=static function()use($q,$reservation):void{unmAssertReplicaVmUuidUndefined($q['vmUuid']);unmCleanupAssertUnreferenced($reservation['storage']);};
    $guard();unmCleanupValidateBatch($plan);
    foreach($plan['storage'] as $s)unmCleanupDeleteZfs($s,$guard);
    foreach($plan['files'] as $f){$guard();unmCleanupDeleteFile($f);}
    $journal['state']='DELETED';$journal['ack']=['success'=>true,'seedId'=>$id,'token'=>$q['token'],'reservationHash'=>$reservationHash];unmCleanupStore($receipt,$journal);
    if(!unlink($path))throw new RuntimeException('Storage removed but reservation finalization failed; retry cleanup.');
    return $journal['ack'];
}
/** Called from the seed worker while its exact seed and global VM locks are held. */
function unmSeedCleanupSource(string $id): array {
    $dir=unmSeedPath($id);$seed=unmSeed($id);$peer=unmPeer((string)$seed['peerId']);$path=$dir.'/cleanup.json';$journal=unmCleanupRead($path);
    $recorded=unmCleanupRead($dir.'/remote-capabilities.json');$protocol=(int)($seed['protocolVersion']??$recorded['protocolVersion']??0);
    if($protocol!==7)throw new RuntimeException('Legacy or unproven prepared-copy ownership: this record is not protocol 7. No cleanup was attempted. Review retained source/destination artifacts; do not fabricate a reservation.');
    if(!$journal){$journal=['schemaVersion'=>1,'token'=>bin2hex(random_bytes(16)),'state'=>'PREFLIGHT','vmUuid'=>$seed['vmUuid'],'peerHostId'=>$peer['hostId']];unmCleanupStore($path,$journal);}
    if($journal['vmUuid']!==$seed['vmUuid']||$journal['peerHostId']!==$peer['hostId'])throw new RuntimeException('Prepared cleanup identity changed.');
    $caps=unmRemote($peer,'/usr/local/sbin/unmotion-agent capabilities',45);$cap=json_decode($caps['stdout'],true);
    if($caps['code']!==0||($cap['protocolVersion']??0)!==7||($cap['seedCleanupVersion']??0)!==1)throw new RuntimeException('Peer lacks Beta6 prepared-cleanup capability. Source bases and destination storage were preserved.');
    if(!isset($journal['sourceSnapshots'])){
        $sources=[];foreach($seed['storage']??[] as $s)if(in_array($s['kind']??'',['dataset','zvol'],true))$sources[$s['source']]=['source'=>$s['source']];
        foreach($seed['storageClaims']['source']??[] as $claim)if(str_starts_with($claim,'zfs:'))$sources[substr($claim,4)]=['source'=>substr($claim,4)];
        $journal['sourceSnapshots']=unmCleanupSourceSnapshots(array_values($sources),'/\Aunmotion-seed-'.preg_quote($id,'/').'-g[1-9][0-9]*\z/D','unmotion:'.$id);
        unmCleanupStore($path,$journal);
    }
    $mapping=(array)($seed['storage']??[]);
    foreach(['storage-new.tsv','held-snapshots.tsv'] as $file)if(is_file($dir.'/'.$file))foreach(file($dir.'/'.$file,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[] as $line){$v=explode("\t",$line);if(count($v)>=3&&in_array($v[0],['dataset','zvol'],true))$mapping[]=['kind'=>$v[0],'source'=>$v[1],'destination'=>$v[2]];}
    $expected=[];foreach($mapping as $s)foreach($journal['sourceSnapshots'] as $name=>$item)if(($s['destination']??'')!==''&&str_starts_with($name,($s['source']??'').'@'))$expected[$s['destination'].substr($name,strlen($s['source']))]=$item['guid'];
    $q=['sourceHostId'=>unmHostId(),'destinationHostId'=>$peer['hostId'],'seedId'=>$id,'vmUuid'=>$seed['vmUuid'],'token'=>$journal['token'],'discardPartial'=>true,'expectedSnapshotGuids'=>$expected];
    if(empty($journal['destinationAck'])){
        $pre=unmRetirementRpc($peer,'seed-cleanup',$q+['phase'=>'review']);
        $ack=unmRetirementRpc($peer,'seed-cleanup',$q+['phase'=>'commit','review'=>$pre['review']]);
        if(($ack['token']??'')!==$journal['token']||($ack['seedId']??'')!==$id||($ack['reservationHash']??'')!==$pre['reservationHash'])throw new RuntimeException('Prepared cleanup acknowledgement identity mismatch.');
        $journal['destinationAck']=$ack;$journal['state']='SOURCE_CLEANUP';unmCleanupStore($path,$journal);
    }
    unmCleanupDeleteSourceSnapshots($journal['sourceSnapshots']);$journal['state']='COMPLETE';unmCleanupStore($path,$journal);
    $seed['state']='REMOVED';$seed['cleanup']=['state'=>'COMPLETE','message'=>'Destination acknowledged cleanup; exact source snapshots released.'];unmAtomicJson($dir.'/seed.json',$seed);
    $archive=UNM_BOOT_DIR.'/archived-seeds';unmTransportPath($archive);if(!is_dir($archive)&&!mkdir($archive,0700,true))throw new RuntimeException('Unable to create cleanup history archive.');
    if(realpath($dir)!==$dir||dirname($dir)!==UNM_SEEDS_DIR||!rename($dir,$archive.'/'.$id.'-cleaned-'.$journal['token']))throw new RuntimeException('Cleanup completed but history archival failed; records retained.');
    return ['success'=>true,'id'=>$id];
}
