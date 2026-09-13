<?php
declare(strict_types=1);
// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner

function unmSeedExecutionBootId(): string {
    return trim((string)@file_get_contents('/proc/sys/kernel/random/boot_id'));
}

/** Absence of a manifest alone is NOT evidence of a never-started transfer. */
function unmSeedNeverStarted(string $dir,array $seed): bool {
    clearstatcache();
    if(($seed['state']??'')!=='FAILED'||!preg_match('/^seed-[a-f0-9]{24}$/D',(string)($seed['id']??'')))return false;
    foreach(['storage','generation','baseSnapshot','pendingGeneration','pendingSnapshot','lastSyncAt','storageStarted'] as $key)if(!empty($seed[$key]))return false;
    $explicit=($seed['executionSchema']??null)===1&&($seed['storageStarted']??null)===false;
    // Do not use an unflushed pre-storage marker as proof after a host restart.
    if(isset($seed['executionSchema'])&&(!$explicit||empty($seed['executionBootId'])||$seed['executionBootId']!==unmSeedExecutionBootId()))return false;
    // Legacy workers open remote-capabilities.json before any snapshot or copy.
    // Failed removal attempts add only the two empty-scan bookkeeping files.
    $allowed=['seed.json','request.cfg','seed.log','launcher.log','storage.tsv','remove-source-candidates.txt'];
    if($explicit)$allowed=array_merge($allowed,['remote-capabilities.json','vm.json','storage-new.tsv','held-snapshots.tsv']);
    $entries=@scandir($dir);if($entries===false||is_link($dir)||realpath($dir)!==$dir)return false;
    foreach($entries as $name){
        if($name==='.'||$name==='..')continue;
        if(!in_array($name,$allowed,true))return false;
        $st=@lstat($dir.'/'.$name);
        if(!$st||($st['mode']&0170000)!==0100000||$st['nlink']!==1)return false;
        if(in_array($name,['storage.tsv','storage-new.tsv','held-snapshots.tsv'],true)&&$st['size']!==0)return false;
    }
    return is_file($dir.'/seed.json')&&is_file($dir.'/request.cfg');
}

/** Only rename this verified record directory; no disk, snapshot or peer operation. */
function unmArchiveUnstartedSeed(string $id): ?array {
    if(!preg_match('/^seed-[a-f0-9]{24}$/D',$id))return null;
    $dir=unmSeedPath($id);$seed=unmSeed($id);
    if(!unmSeedNeverStarted($dir,$seed))return null;
    $locks=[];
    try {
        $uuid=(string)($seed['vmUuid']??'');
        if(!preg_match('/^[A-Fa-f0-9-]{32,36}$/D',$uuid))throw new RuntimeException('Invalid prepared-copy VM identity.');
        // Match worker lock ordering, including exclusion of legacy exclusive workers.
        foreach([['/var/lock/unmotion-seed-'.$id.'.lock',LOCK_EX],['/var/run/unmotion-seed.lock',LOCK_SH],['/var/lock/unmotion-'.$uuid.'.lock',LOCK_EX]] as [$path,$mode]){
            $lock=fopen($path,'c');if(!$lock)throw new RuntimeException('Unable to lock failed seed archive.');$locks[]=$lock;
            if(!flock($lock,$mode|LOCK_NB))throw new RuntimeException('An operation is still active; the failed seed was not archived.');
        }
        $seed=unmSeed($id);if(!unmSeedNeverStarted($dir,$seed))return null;
        $root=UNM_BOOT_DIR.'/archived-seeds';
        if(is_link($root)||(!is_dir($root)&&!mkdir($root,0700))||realpath($root)!==$root)throw new RuntimeException('Unsafe or unavailable failed-seed archive directory.');
        $target=$root.'/'.$id.'-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4));
        if(file_exists($target)||!rename($dir,$target))throw new RuntimeException('Unable to archive the failed seed; its record was retained.');
        return array_replace($seed,['state'=>'REMOVED','recordOnly'=>true,'archivePath'=>$target,'message'=>'Never-started seed record archived; logs retained. No VM storage was changed.']);
    } finally {foreach(array_reverse($locks) as $lock){flock($lock,LOCK_UN);fclose($lock);}}
}

/** Durable boundary written before the worker can create a snapshot or modify a copy. */
function unmSeedMarkStorageStarted(string $id): void {
    $seed=unmSeed($id);$seed['storageStarted']=true;
    unmAtomicJson(unmSeedPath($id).'/seed.json',$seed);
}
