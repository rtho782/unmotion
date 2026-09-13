<?php
declare(strict_types=1);
require_once __DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/lib.php';
function seedCheck(bool $ok,string $why):void {if(!$ok)throw new RuntimeException($why);}
function seedDenied(callable $fn):void {$denied=false;try{$fn();}catch(RuntimeException $e){$denied=true;}seedCheck($denied,'Expected conflicting admission to be refused');}
$a=unmSeedStorageClaims([['kind'=>'zfs-filesystem','source'=>'pool/domains/Squid Proxy','destination'=>'other/domains/Squid Proxy','sourceMountpoint'=>'/mnt/pool/domains/Squid Proxy','destinationMountpoint'=>'/mnt/other/domains/Squid Proxy']]);
$b=unmSeedStorageClaims([['kind'=>'image','source'=>'/mnt/pool/shared/VM B/vdisk1.img','destination'=>'/mnt/other/domains/VM B/vdisk1.img']]);
$record=['id'=>'seed-a','vmUuid'=>'vm-a','peerId'=>'peer-a','peerHostId'=>'host-a','state'=>'TRANSFERRING','storageClaims'=>$a];
unmSeedAssertClaims('seed-b','vm-b','peer-a','host-a',$b,[$record]);
seedDenied(fn()=>unmSeedAssertClaims('seed-b','vm-a','peer-b','host-b',$b,[$record]));
seedDenied(fn()=>unmSeedAssertClaims('seed-b','vm-b','peer-b','host-b',$a,[$record]));
$collision=$b;$collision['destination']=['path:/mnt/other/domains/Squid Proxy/vdisk2.img'];
seedDenied(fn()=>unmSeedAssertClaims('seed-b','vm-b','peer-alias','host-a',$collision,[$record]));
unmSeedAssertClaims('seed-b','vm-b','peer-b','host-b',$collision,[$record]);
$sameVmIdle=$record;$sameVmIdle['state']='READY';
unmSeedAssertClaims('seed-b','vm-a','peer-b','host-b',$a,[$sameVmIdle]);
foreach(['READY','FAILED','INTERRUPTED'] as $state){$retained=$record;$retained['state']=$state;seedDenied(fn()=>unmSeedAssertClaims('seed-b','vm-b','peer-a','host-a',$collision,[$retained]));}
seedCheck(!unmSeedClaimsOverlap(['zfs:pool/vm'],['zfs:pool/vm-other']),'Sibling prefix falsely overlaps');
seedCheck(unmSeedClaimsOverlap(['zfs:pool/vm'],['zfs:pool/vm/child']),'Dataset descendants not protected');
$vm=['name'=>'Squid Proxy','disks'=>[['transferClass'=>'shared-zfs-image','source'=>'/mnt/user/domains/Squid Proxy/vdisk1.img','resolvedSource'=>'/mnt/pool/domains/Squid Proxy/vdisk1.img']]];
$caps=['storage'=>['imageDirectory'=>'/mnt/other/domains']];
$planned=['storageClaims'=>unmSeedStorageClaims(unmSeedWorkerPlan($vm,$caps))];
unmSeedAssertWorkerPlan($planned,$vm,$caps);
$changed=$caps;$changed['storage']['imageDirectory']='/mnt/changed/domains';
seedDenied(fn()=>unmSeedAssertWorkerPlan($planned,$vm,$changed));
seedCheck(in_array('path:/mnt/pool/domains/Squid Proxy/vdisk1.img',$planned['storageClaims']['source'],true),'FUSE direct source alias omitted');
seedDenied(fn()=>unmSeedAssertClaims('seed-b','vm-b','peer-a','host-a',$b,[['id'=>'legacy','state'=>'STARTING']]));
$legacy=['id'=>'legacy','state'=>'READY','peerId'=>'peer-a','storage'=>[['kind'=>'dataset','source'=>'pool/legacy','destination'=>'other/domains/Squid Proxy','destinationMountpoint'=>'/mnt/other/domains/Squid Proxy']]];
seedDenied(fn()=>unmSeedAssertClaims('seed-b','vm-b','peer-a','host-a',$collision,[$legacy]));
$tmp=sys_get_temp_dir().'/unmotion-seed-claims-'.bin2hex(random_bytes(6));mkdir($tmp,0700);
try {
    file_put_contents($tmp.'/disk','fixture');link($tmp.'/disk',$tmp.'/alias');
    $left=unmSeedStorageClaims([['kind'=>'image','source'=>$tmp.'/disk','destination'=>'/mnt/dest/a']]);
    $right=unmSeedStorageClaims([['kind'=>'image','source'=>$tmp.'/alias','destination'=>'/mnt/dest/b']]);
    seedCheck(unmSeedClaimsOverlap($left['source'],$right['source']),'Hardlinked source disk alias not detected');
}finally{unlink($tmp.'/alias');unlink($tmp.'/disk');rmdir($tmp);}
$worker=file_get_contents(__DIR__.'/../src/rootfs/usr/local/sbin/unmotion-seed-worker');
seedCheck(str_contains($worker,'flock -sn 9'),'Independent workers not sharing the compatibility lock');
seedCheck(strpos($worker,'flock -n 7')<strpos($worker,'source "$REQUEST"'),'Duplicate worker can read a mutable request before locking');
seedCheck((bool)preg_match('/flock -n 7[^\n]+echo [^\n]+exit 5/',$worker),'Duplicate worker must exit without writing seed state');
$api=file_get_contents(__DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/api.php');
foreach(['prepareWarm','updateWarm','resumeWarm','removeWarm'] as $action)seedCheck(str_contains($api,"case '$action': requirePostMutation();"),'Missing explicit POST gate: '.$action);
echo "Warm preparation admission, retained storage, peer identity, path overlap and duplicate-worker regressions passed.\n";
