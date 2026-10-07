<?php
declare(strict_types=1);
// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner

function unmParallelWarmCutover(array $pre,array $options): bool {
    return !empty($options['warm_seed_id'])&&!empty($pre['destination']['features']['parallelWarmCutover'])
        &&($options['destination_conflict_action']??'cancel')!=='overwrite'
        &&(empty($pre['vm']['isos'])||($options['iso_action']??'copy')!=='copy');
}

function unmMigrationStorageClaims(array $pre): array {
    $plan=unmSeedWorkerPlan((array)$pre['vm'],(array)$pre['destination']);
    foreach($pre['plan']??[] as $item)if(($item['kind']??'')==='custom')$plan[]=['kind'=>'image','source'=>$item['source'],'destination'=>$item['destination']];
    return unmSeedStorageClaims($plan);
}

/** Called under short source admission lock, not held during transfer. */
function unmCheckMigrationReservations(array $pre,array $options,array $jobs,array $seeds,string $ownJobId=''): array {
    $uuid=(string)$pre['vm']['uuid'];$peerId=(string)($pre['peer']['id']??'');
    if($peerId==='')throw new RuntimeException('Paired destination identity is unavailable.');$hostId=(string)($pre['destination']['hostId']??'');
    $claims=unmMigrationStorageClaims($pre);$seedId=(string)($options['warm_seed_id']??'');
    if(!$claims['source']||!$claims['destination'])throw new RuntimeException('Migration storage could not be reserved safely.');
    unmSeedAssertClaims($seedId,$uuid,$peerId,$hostId,$claims,$seeds);
    $parallel=unmParallelWarmCutover($pre,$options);$reserved=0;
    foreach($jobs as $job){
        if(($job['id']??'')===$ownJobId)continue;
        $otherUuid=(string)($job['request']['VM_UUID']??'');
        if(unmActiveVmJob($otherUuid,[$job])===null)continue;
        if($uuid===$otherUuid)throw new RuntimeException('Another migration/clone job already owns this VM.');
        $other=(array)($job['migrationClaims']??[]);
        $samePeer=($job['peerId']??'')===$peerId||($hostId!==''&&($job['preflight']['destination']['hostId']??'')===$hostId);
        if(unmSeedClaimsOverlap($claims['source'],$other['source']??[])||($samePeer&&unmSeedClaimsOverlap($claims['destination'],$other['destination']??[])))throw new RuntimeException('Another migration job reserves overlapping VM storage. Existing jobs were preserved.');
        if($parallel&&$samePeer&&!empty($job['request']['PARALLEL_WARM_CUTOVER'])&&!in_array($job['state']??'',['OWNERSHIP_HANDOFF','COMPLETE','COMPLETE_WITH_WARNINGS','COMPLETE_CLEANED'],true))$reserved+=(int)($job['request']['TARGET_MEMORY_KIB']??0)*1024;
    }
    $requested=(int)($pre['resourcePlan']['requested']['memoryKiB']??$pre['vm']['memoryKiB']??0)*1024;
    if($parallel&&$ownJobId===''&&$reserved+$requested>(int)($pre['destination']['resources']['availableBytes']??0))throw new RuntimeException('Insufficient unreserved destination RAM for this cutover alongside already admitted cutovers.');
    return $claims;
}
