<?php
declare(strict_types=1);
// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
require __DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/preflight-policy.php';
function policyCheck(bool $ok,string $label):void {if(!$ok)throw new RuntimeException($label);}
$caps=['storage'=>['imageDirectory'=>'/mnt/cache/domains','imageZfsContainingDataset'=>'cache/domains','isoDirectory'=>'/mnt/isos','isoZfsContainingDataset'=>'iso-pool/media']];
$zvol=unmMigrationHealthScope([['kind'=>'zvol','destination'=>'cache/vm-zvols/Squid Proxy']],$caps);
$image=unmMigrationHealthScope([['kind'=>'image','destination'=>'/mnt/cache/domains/Squid Proxy/vdisk.img']],$caps);
$iso=unmMigrationHealthScope([['kind'=>'iso','action'=>'copy','destination'=>'/mnt/isos/Ubuntu.iso']],$caps);
$unknown=unmMigrationHealthScope([['kind'=>'image','destination'=>'/mnt/user/domains/Squid Proxy/vdisk.img']],$caps);
$array=unmMigrationHealthScope([['kind'=>'image','destination'=>'/mnt/disk1/domains/Squid Proxy/vdisk.img']],[]);
$critical=static fn(string $code,array $context=[]):array=>['code'=>$code,'blocksMigration'=>true,'severity'=>'critical','context'=>$context];
$disposition=static fn(array $issue,array $scope,bool $storageOnly=false):string=>unmMigrationHealthDisposition($issue,$scope,$storageOnly,'vm-1');
policyCheck(!$zvol['image']&&isset($zvol['pools']['cache'])&&!$zvol['unknownStorage'],'zvol-only dependencies');
policyCheck($image['image']&&isset($image['pools']['cache']),'image pool mapping');
policyCheck($iso['iso']&&isset($iso['pools']['iso-pool']),'ISO copy mapping');
policyCheck($unknown['unknownStorage'],'FUSE/unknown mapping retains global storage blockers');
foreach(['IMAGE_PATH_MISSING','IMAGE_PATH_READONLY'] as $code){
    policyCheck($disposition($critical($code),$zvol)==='ignore','unused image path');
    policyCheck($disposition($critical($code),$image)==='error','used image path');
}
policyCheck($disposition($critical('ISO_PATH_MISSING'),$zvol)==='ignore','unused ISO path');
policyCheck($disposition($critical('ISO_PATH_MISSING'),$iso)==='error','used ISO path');
foreach(['ZFS_POOL_FAULTED','ZFS_DATA_ERRORS'] as $code){
    policyCheck($disposition($critical($code,['pool'=>'cache']),$zvol,true)==='error','used pool fails even preparation');
    policyCheck($disposition($critical($code,['pool'=>'other']),$zvol)==='ignore','proven unused pool');
    policyCheck($disposition($critical($code,['pool'=>'other']),$unknown)==='error','unknown pool dependency');
    policyCheck($disposition($critical($code),$zvol)==='error','old peer missing fault context');
}
policyCheck($disposition($critical('ARRAY_DISK_FAULT',['disk'=>'disk2']),$array)==='ignore','different array disk');
policyCheck($disposition($critical('ARRAY_DISK_FAULT',['disk'=>'disk1']),$array)==='error','selected array disk');
policyCheck($disposition($critical('ARRAY_DISK_FAULT',['disk'=>'parity']),$array)==='error','parity protects array writes');
policyCheck($disposition($critical('ARRAY_DISK_FAULT',['disk'=>'cache']),$zvol)==='error','ambiguous device identity retained');
policyCheck($disposition($critical('ZFS_VERSION_MISMATCH'),$array)==='ignore','non-ZFS transfer');
policyCheck($disposition($critical('ZFS_VERSION_MISMATCH'),$zvol)==='error','ZFS transfer');
foreach(['LIBVIRT_UNAVAILABLE','PAIRING_BROKEN','PEER_UNREACHABLE','NEW_CRITICAL_FAULT'] as $code)
    policyCheck($disposition($critical($code,['pool'=>'other']),$zvol,true)==='error','unscoped critical fault '.$code);
policyCheck($disposition($critical('MEMORY_CRITICAL'),$zvol,true)==='note','host memory note during seed');
policyCheck($disposition($critical('MEMORY_CRITICAL'),$zvol,false)==='error','host memory blocker at cutover');
policyCheck($disposition(['code'=>'VM_CRASHED','context'=>['vmUuid'=>'other']],$zvol)==='ignore','unrelated crashed VM');
policyCheck($disposition(['code'=>'ZFS_SCAN_ACTIVE','message'=>'scrub in progress','context'=>['pool'=>'cache']],$zvol)==='note','routine scrub');
policyCheck($disposition(['code'=>'ZFS_SCAN_ACTIVE','message'=>'resilver in progress','context'=>['pool'=>'cache']],$zvol)==='warning','resilver remains warning');
$custom=unmMigrationHealthScope([['kind'=>'custom','destination'=>'/mnt/cache/domains/Squid Proxy/vars.fd']],$caps);
policyCheck($custom['image']&&isset($custom['pools']['cache']),'custom NVRAM dependency');
$omitted=unmMigrationHealthScope([['kind'=>'zvol','destination'=>'cache/vm-zvols/Squid Proxy'],['kind'=>'iso','action'=>'remove','destination'=>'/mnt/isos/Ubuntu.iso']],$caps);
policyCheck(!$omitted['iso']&&!isset($omitted['pools']['iso-pool']),'omitted ISO has no dependency');
$retained=unmMigrationHealthScope([['kind'=>'iso','action'=>'retain','source'=>'/mnt/disk1/media/boot.iso','destination'=>'/mnt/isos/boot.iso']],$caps);
policyCheck(!$retained['iso']&&isset($retained['arrayDisks']['disk1']),'retained ISO checks original path, not copy directory');
foreach([[],['cpuOnlineCount'=>2,'availableBytes'=>128*1024*1024,'totalBytes'=>4*1024*1024*1024]] as $resources){
    $prepare=unmMigrationResourceMessages(4,512*1024,$resources,true);
    $cutover=unmMigrationResourceMessages(4,512*1024,$resources,false);
    policyCheck(!$prepare['errors']&&!$prepare['warnings']&&count($prepare['notes'])===2,'prepare/update defer startup resources');
    policyCheck(count($cutover['errors'])===2,'cold/cutover retain resource gates');
}
date_default_timezone_set('Europe/London');$now=strtotime('2026-09-13 14:00:00');
foreach(['Sep 13 13:59:00 host kernel: Out of memory','2026-09-13T12:59:00Z host kernel: Killed process 100'] as $line)
    policyCheck(unmRecentOomEvent($line,$now)['ageSeconds']===60,'fresh timestamp');
foreach(['Sep 13 12:59:59 host kernel: oom-killer','Sep 13 14:01:00 host kernel: oom-killer','no timestamp: out of memory','Sep 13 13:59:00 host kernel: normal event'] as $line)
    policyCheck(unmRecentOomEvent($line,$now)===null,'old/future/unparseable/non-OOM event');
policyCheck(unmRecentOomEvent('Dec 31 23:59:30 host kernel: oom-killer',strtotime('2027-01-01 00:00:30'))['ageSeconds']===60,'year rollover');
$lib=file_get_contents(__DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/lib.php');
$api=file_get_contents(__DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/api.php');
policyCheck(str_contains($lib,"['migration_mode'=>'warm-update','warm_seed_id'=>\$id"),'seed update uses storage-only preflight');
policyCheck(str_contains($api,"\$opts['migration_mode']=\$warmSeedId!==''?'warm-cutover':'cold';"),'start endpoint cannot bypass cutover preflight');
echo "Preflight resource phases, dependency-scoped health, mixed-peer unknowns and OOM timestamp regressions passed.\n";
