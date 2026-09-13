<?php
// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
declare(strict_types=1);
require __DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/lib.php';
function parallelCheck(bool $ok,string $why):void {if(!$ok)throw new RuntimeException($why);}
function parallelReject(callable $fn,string $why):void {try{$fn();}catch(RuntimeException $e){return;}throw new RuntimeException('Accepted '.$why);}
$vm=['uuid'=>'74100000-1111-4111-8111-000000000090','name'=>'Squid Proxy','memoryKiB'=>524288,'isos'=>[],'disks'=>[['transferClass'=>'zvol','source'=>'/dev/zvol/source/Squid Proxy']]];
$pre=['vm'=>$vm,'peer'=>['id'=>'peer-1'],'destination'=>['hostId'=>'host-1','features'=>['parallelWarmCutover'=>true],'storage'=>['zvolDataset'=>'dest/vms'],'resources'=>['availableBytes'=>1024*1024*1024]],'resourcePlan'=>['requested'=>['memoryKiB'=>524288]],'plan'=>[]];
$opts=['warm_seed_id'=>'seed-'.str_repeat('a',24),'iso_action'=>'remove','destination_conflict_action'=>'cancel'];
parallelCheck(unmParallelWarmCutover($pre,$opts),'modern independent warm cutover');
$old=$pre;$old['destination']['features']=[];parallelCheck(!unmParallelWarmCutover($old,$opts),'mixed peer uses exclusive queue');
parallelCheck(!unmParallelWarmCutover($pre,array_replace($opts,['warm_seed_id'=>''])),'cold remains exclusive');
parallelCheck(!unmParallelWarmCutover($pre,array_replace($opts,['destination_conflict_action'=>'overwrite'])),'overwrite exclusive');
$withIso=$pre;$withIso['vm']['isos']=['/mnt/isos/common.iso'];parallelCheck(!unmParallelWarmCutover($withIso,array_replace($opts,['iso_action'=>'copy'])),'copied shared media exclusive');
$claims=unmCheckMigrationReservations($pre,$opts,[],[]);parallelCheck(count($claims['source'])>0,'actual storage reservations');
$job=['id'=>'other','state'=>'TRANSFERRING','peerId'=>'peer-1','request'=>['VM_UUID'=>'74100000-1111-4111-8111-000000000091','PARALLEL_WARM_CUTOVER'=>true,'TARGET_MEMORY_KIB'=>524288],'migrationClaims'=>['source'=>['zfs:source/Other'],'destination'=>['zfs:dest/vms/Other']],'preflight'=>['destination'=>['hostId'=>'host-1']]];
parallelCheck(unmCheckMigrationReservations($pre,$opts,[$job],[])===$claims,'independent transfers within total RAM');
$job['request']['TARGET_MEMORY_KIB']=524289;parallelReject(fn()=>unmCheckMigrationReservations($pre,$opts,[$job],[]),'aggregate destination RAM overcommit');
$job['request']['TARGET_MEMORY_KIB']=524288;$job['migrationClaims']['destination']=$claims['destination'];parallelReject(fn()=>unmCheckMigrationReservations($pre,$opts,[$job],[]),'overlapping destination');
$job['peerId']='peer-alias';parallelReject(fn()=>unmCheckMigrationReservations($pre,$opts,[$job],[]),'same destination host through peer alias');
$job['preflight']['destination']['hostId']='other-host';$job['migrationClaims']['source']=$claims['source'];parallelReject(fn()=>unmCheckMigrationReservations($pre,$opts,[$job],[]),'overlapping source across destinations');
$seed=['id'=>'seed-'.str_repeat('b',24),'vmUuid'=>$job['request']['VM_UUID'],'peerId'=>'peer-1','state'=>'READY','storageClaims'=>$claims];parallelReject(fn()=>unmCheckMigrationReservations($pre,$opts,[],[$seed]),'another retained seed');
echo "Parallel warm eligibility, mixed-version queue, storage claims and aggregate RAM admission regressions passed.\n";
