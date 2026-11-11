<?php
// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
declare(strict_types=1);

/** Local kernel reads only. diskseq changes when a block device is registered again. */
function unmLiveZvolKernel(string $path): array {
    clearstatcache(true,$path);$node=realpath($path);$s=@stat($path);
    if(!is_string($node)||!preg_match('~\A/dev/(zd[0-9]+)\z~D',$node,$m)||!$s||($s['mode']&0170000)!==0060000)throw new RuntimeException('Zvol is not a whole kernel ZFS block device.');
    $sys='/sys/class/block/'.$m[1];$seq=trim((string)@file_get_contents($sys.'/diskseq'));$dev=trim((string)@file_get_contents($sys.'/dev'));$sectors=trim((string)@file_get_contents($sys.'/size'));
    if(trim((string)@file_get_contents($sys.'/ro'))!=='0')throw new RuntimeException('Zvol kernel device is read-only or its writable state is unavailable.');
    if(!preg_match('/\A[1-9][0-9]*\z/D',$seq)||!preg_match('/\A[0-9]+:[0-9]+\z/D',$dev)||!ctype_digit($sectors)||(float)$sectors>PHP_INT_MAX/512)throw new RuntimeException('Kernel block identity/size proof unavailable.');
    clearstatcache(true,$path);$after=@stat($path);if(realpath($path)!==$node||!$after||$s['rdev']!==$after['rdev']||$s['ino']!==$after['ino']||trim((string)@file_get_contents($sys.'/diskseq'))!==$seq)throw new RuntimeException('Zvol kernel identity changed while reading.');
    return ['node'=>$node,'diskseq'=>$seq,'dev'=>$dev,'rdev'=>$s['rdev'],'bytes'=>(int)$sectors*512];
}
function unmLiveZvolProofKey(array $r): string {return hash('sha256',json_encode(['jobId'=>$r['jobId'],'vmUuid'=>$r['vmUuid'],'disks'=>unmLiveDisks($r)],JSON_THROW_ON_ERROR));}
function unmLiveZvolNativeCheck(array $r,string $boot,string $proofPath): void {
    if(($r['workflow']??'')==='ordinary')return;
    $volumes=array_filter(unmLiveDisks($r),static fn($d)=>($d['type']??'')==='zvol');if(!$volumes)return;
    if(is_link($proofPath)||!is_file($proofPath))throw new RuntimeException('Local ZFS identity verification is pending; this VM remains fenced.');
    $proof=unmNativeJson($proofPath);
    if(($proof['bootId']??'')!==$boot||($proof['binding']??'')!==unmLiveZvolProofKey($r))throw new RuntimeException('Stale local ZFS identity proof.');
    foreach($volumes as $disk){unmLiveVolumeContract($disk['volume']??null);if(($proof['devices'][$disk['target']]??null)!==unmLiveZvolKernel($disk['path']))throw new RuntimeException('Zvol was replaced or is unavailable; fresh ZFS verification is required.');}
}
