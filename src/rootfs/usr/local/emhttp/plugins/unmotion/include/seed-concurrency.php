<?php
declare(strict_types=1);
// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner

/** Short admission transaction, not a transfer-wide lock. */
function unmSeedAdmission(callable $operation): array {
    $lock=fopen('/var/lock/unmotion-seed-admission.lock','c');
    if(!$lock)throw new RuntimeException('Unable to lock Warm Move admission.');
    try {
        $deadline=microtime(true)+60;
        while(!flock($lock,LOCK_EX|LOCK_NB)) {
            if(microtime(true)>=$deadline)throw new RuntimeException('Warm Move admission is busy; retry shortly. Existing transfers are unaffected.');
            usleep(100000);
        }
        return $operation();
    } finally { flock($lock,LOCK_UN); fclose($lock); }
}

function unmSeedOperationActive(array $seed): bool {
    return !in_array((string)($seed['state']??''),['READY','FAILED','INTERRUPTED','REMOVED'],true);
}

function unmSeedAssertIdle(string $id, string $uuid): void {
    if(!preg_match('/^[A-Fa-f0-9-]{32,36}$/D',$uuid))throw new RuntimeException('Invalid prepared-copy VM identity.');
    $existing=unmLoadJson(unmSeedPath($id).'/seed.json');
    if($existing && unmSeedOperationActive($existing))throw new RuntimeException('A Warm Move operation is already queued or active for this prepared copy.');
    foreach(['/var/lock/unmotion-seed-'.$id.'.lock','/var/lock/unmotion-'.$uuid.'.lock'] as $path) {
        $lock=fopen($path,'c');
        if(!$lock)throw new RuntimeException('Unable to check the VM operation lock.');
        try { if(!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('Another unMotion operation is already using this VM or prepared copy.'); }
        finally { fclose($lock); }
    }
}

/** Include dataset identities and mount paths, so directory/image overlap is visible. */
function unmSeedStorageClaims(array $plan): array {
    $out=['source'=>[],'destination'=>[]];
    foreach($plan as $item) {
        $kind=(string)($item['kind']??'');
        if(!in_array($kind,['zvol','dataset','zfs-filesystem','image'],true))continue;
        foreach(['source','destination'] as $side) {
            $path=(string)($item[$side]??'');if($path==='')continue;
            $native=$kind!=='image';
            $out[$side][]=($native?'zfs:':'path:').rtrim($side==='source'&&!$native?(realpath($path)?:$path):$path,'/');
            if(!$native && $side==='source' && ($st=@stat($path)))$out[$side][]='inode:'.$st['dev'].':'.$st['ino'];
            if($side==='source' && !empty($item['sourceResolved']))$out[$side][]='path:'.(realpath($item['sourceResolved'])?:$item['sourceResolved']);
            $mount=(string)($item[$side.'Mountpoint']??'');
            if($mount!=='')$out[$side][]='path:'.rtrim($side==='source'?(realpath($mount)?:$mount):$mount,'/');
            if($kind==='zvol'&&$side==='source')$out[$side][]='path:'.(realpath('/dev/zvol/'.$path)?:'/dev/zvol/'.$path);
        }
    }
    foreach($out as &$paths){$paths=array_values(array_unique($paths));sort($paths);}unset($paths);
    return $out;
}

/** Match the seed worker's mappings, including direct paths behind Unraid FUSE. */
function unmSeedWorkerPlan(array $vm,array $caps): array {
    $storage=(array)($caps['storage']??[]);$plan=[];
    foreach((array)($vm['disks']??[]) as $disk) {
        $class=(string)($disk['transferClass']??'');$src=(string)($disk['source']??'');
        if($class==='zvol'){$src=substr($src,strlen('/dev/zvol/'));$plan[]=['kind'=>'zvol','source'=>$src,'destination'=>rtrim((string)($storage['zvolDataset']??''),'/').'/'.basename($src)];}
        elseif($class==='zfs-dataset-image'){$src=(string)$disk['zfsDataset'];$mount=(string)$disk['zfsMountpoint'];$plan[]=['kind'=>'dataset','source'=>$src,'destination'=>rtrim((string)($storage['imageZfsDataset']??''),'/').'/'.basename($src),'sourceMountpoint'=>$mount,'destinationMountpoint'=>rtrim((string)($storage['imageDirectory']??''),'/').'/'.basename($mount)];}
        elseif(in_array($class,['shared-zfs-image','encrypted-zfs-image','non-zfs-image'],true))$plan[]=['kind'=>'image','source'=>$src,'sourceResolved'=>$disk['resolvedSource']??$src,'destination'=>rtrim((string)($storage['imageDirectory']??''),'/').'/'.$vm['name'].'/'.basename($src)];
    }
    return $plan;
}

function unmSeedAssertWorkerPlan(array $seed,array $vm,array $caps): void {
    if(isset($seed['storageClaims'])&&$seed['storageClaims']!==unmSeedStorageClaims(unmSeedWorkerPlan($vm,$caps)))throw new RuntimeException('VM storage or destination settings changed after Warm Move admission. No new snapshot or transfer was started; review the prepared copy.');
}

function unmSeedClaimsOverlap(array $a,array $b): bool {
    foreach($a as $left)foreach($b as $right)if($left===$right||str_starts_with($left,$right.'/')||str_starts_with($right,$left.'/'))return true;
    return false;
}

/** Called while admission is locked; retained/partial destinations remain reserved. */
function unmSeedAssertClaims(string $id,string $uuid,string $peerId,string $peerHostId,array $claims,array $seeds): void {
    foreach($seeds as $seed) {
        if(($seed['id']??'')===$id||($seed['state']??'')==='REMOVED')continue;
        if(($seed['vmUuid']??'')===$uuid&&unmSeedOperationActive($seed))throw new RuntimeException('Another Warm Move operation is already queued or active for this VM.');
        $other=$seed['storageClaims']??unmSeedStorageClaims((array)($seed['storage']??[]));
        if(empty($other['source'])&&unmSeedOperationActive($seed))throw new RuntimeException('An older active preparation has no storage reservation; let it finish before starting another.');
        if((($seed['vmUuid']??'')!==$uuid||unmSeedOperationActive($seed))&&unmSeedClaimsOverlap($claims['source']??[],$other['source']??[]))throw new RuntimeException('Another prepared copy references overlapping source storage. Use independent VM disks.');
        $samePeer=($seed['peerId']??'')===$peerId||($peerHostId!==''&&($seed['peerHostId']??'')===$peerHostId);
        if($samePeer&&unmSeedClaimsOverlap($claims['destination']??[],$other['destination']??[]))throw new RuntimeException('Another prepared copy reserves overlapping destination storage. Existing transfers and partial copies were preserved.');
    }
}

function unmSeedRecords(): array {
    $out=[];foreach(glob(UNM_SEEDS_DIR.'/*/seed.json')?:[] as $path){$seed=unmLoadJson($path);if($seed)$out[]=$seed;}return $out;
}

function unmStartSeed(string $vmIdentifier,string $peerId,string $action='prepare'): array {
    return unmSeedAdmission(fn()=>unmStartSeedAdmitted($vmIdentifier,$peerId,$action));
}
function unmResumeSeed(string $id): array {
    return unmSeedAdmission(function()use($id){$seed=unmSeed($id);unmSeedAssertIdle($id,(string)$seed['vmUuid']);unmSeedAssertClaims($id,(string)$seed['vmUuid'],(string)$seed['peerId'],(string)($seed['peerHostId']??''),$seed['storageClaims']??unmSeedStorageClaims((array)($seed['storage']??[])),unmSeedRecords());return unmResumeSeedAdmitted($id);});
}
function unmRemoveSeed(string $id): array {
    return unmSeedAdmission(function()use($id){
        $seed=unmSeed($id);unmSeedAssertIdle($id,(string)$seed['vmUuid']);
        $archived=unmArchiveUnstartedSeed($id);if($archived!==null)return $archived;
        unmSeedAssertClaims($id,(string)$seed['vmUuid'],(string)$seed['peerId'],(string)($seed['peerHostId']??''),$seed['storageClaims']??unmSeedStorageClaims((array)($seed['storage']??[])),unmSeedRecords());
        return unmRemoveSeedAdmitted($id);
    });
}
