<?php
declare(strict_types=1);
require_once __DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/lib.php';
function portableCheck(bool $ok,string $why):void {if(!$ok)throw new RuntimeException($why);}
function portableReject(callable $test,string $why):void {try{$test();}catch(RuntimeException $e){return;}throw new RuntimeException('Accepted unsafe case: '.$why);}
$root='/mnt/unmotion-portability-'.bin2hex(random_bytes(6));mkdir($root,0700);
$uuid=unmNewUuid();$created=[];
function makeTestDir(string $path):void {
    if(is_dir($path))return;
    makeTestDir(dirname($path));mkdir($path,0700);$GLOBALS['created'][]=$path;
}
try{
    file_put_contents($root.'/code.fd','firmware code');file_put_contents($root.'/alternate.fd','firmware code');file_put_contents($root.'/vars.fd','firmware vars');file_put_contents($root.'/wrong.fd','wrong content');
    $request=['loader'=>unmFirmwareFile($root.'/code.fd'),'template'=>unmFirmwareFile($root.'/vars.fd')];
    $request['loader']['path']=$root.'/missing.fd';
    $plan=unmFirmwareResolve($request,[$root.'/wrong.fd',$root.'/alternate.fd',$root.'/vars.fd']);
    portableCheck($plan['loader']['destination']===$root.'/alternate.fd','equivalent firmware path mapping');
    portableReject(fn()=>unmFirmwareResolve($request,[$root.'/wrong.fd']),'different firmware is not equivalent');
    $request['template']['path']=$root.'/missing-vars.fd';
    portableReject(fn()=>unmFirmwareResolve($request,[$root.'/alternate.fd']),'template layout/content mismatch');
    unmHostStateNoNestedMounts('/state',"1 0 0:1 / / rw - tmpfs tmpfs rw\n");
    portableReject(fn()=>unmHostStateNoNestedMounts('/state',"1 0 0:1 / /state/nested rw - tmpfs tmpfs rw\n"),'nested mount');
    portableReject(fn()=>unmHostStateNoNestedMounts('/state dir',"1 0 0:1 / /state\\040dir/nested rw - tmpfs tmpfs rw\n"),'escaped mountpoint');
    $legacy='/etc/libvirt/qemu/nvram/unmotion-legacy-'.bin2hex(random_bytes(6)).'.fd';
    makeTestDir(dirname($legacy));file_put_contents($legacy,'legacy variables');
    $legacyEvidence=unmHostStateEvidence($legacy,'nvram',$uuid);
    portableCheck($legacyEvidence['cleanupAllowed']===false,'non-UUID legacy NVRAM remains transferable without deletion permission');
    unmVerifyHostStateEvidence($legacyEvidence);
    portableReject(fn()=>unmRemoveHostStateEvidence($legacyEvidence),'legacy filename cleanup');
    portableCheck(is_file($legacy),'legacy NVRAM retained');unlink($legacy);
    $escape=dirname($legacy).'/unmotion-escape-'.bin2hex(random_bytes(6));
    file_put_contents($root.'/'.$uuid.'_VARS.fd','outside variables');symlink($root,$escape);
    portableReject(fn()=>unmHostStateEvidence($escape.'/'.$uuid.'_VARS.fd','nvram',$uuid),'NVRAM parent link escape');
    portableCheck(is_file($root.'/'.$uuid.'_VARS.fd'),'escaped NVRAM untouched');unlink($escape);unlink($root.'/'.$uuid.'_VARS.fd');
    $e=unmHostStateEvidence($root.'/vars.fd','nvram',$uuid);
    file_put_contents($root.'/vars.fd','changed vars');
    portableReject(fn()=>unmVerifyHostStateEvidence($e),'state content changed');
    $e=unmHostStateEvidence($root.'/vars.fd','nvram',$uuid);
    unmRemoveHostStateEvidence($e);portableCheck(!file_exists($root.'/vars.fd'),'exact variables-file cleanup');
    $one='/var/lib/libvirt/swtpm/'.$uuid;$two='/etc/libvirt/swtpm/'.$uuid;
    makeTestDir($one);file_put_contents($one.'/tpm2-00.permall','TPM');
    $xml="<domain><devices><tpm><backend type='emulator'/></tpm></devices></domain>";
    portableCheck(unmTpmStatePath($uuid,$xml)===realpath($one),'exact TPM discovery');
    makeTestDir($two);
    portableReject(fn()=>unmTpmStatePath($uuid,$xml),'distinct TPM stores');
    rmdir($two);array_pop($created);
    $e=unmHostStateEvidence((string)realpath($one),'tpm',$uuid);
    file_put_contents($one.'/unexpected','new file');
    portableReject(fn()=>unmRemoveHostStateEvidence($e),'new TPM directory contents');
    portableCheck(is_file($one.'/tpm2-00.permall'),'rejected cleanup preserves original');
    unlink($one.'/unexpected');unmRemoveHostStateEvidence($e);
    $source="/mnt/cache/domains/Squid Proxy/vdisk1.img";$nv="/mnt/cache/domains/Squid Proxy/OVMF_VARS.fd";
    $xml="<domain><name>Squid Proxy</name><uuid>$uuid</uuid><os><nvram format='raw'>$nv</nvram></os><devices><disk type='file' device='disk'><source file='$source'/></disk></devices></domain>";
    $out=unmRecoveryTransformDomainXml($xml,[$source=>'/mnt/destination/activation/disk.img']);
    portableCheck(unmMigrationNvramXml($out)==='/etc/libvirt/qemu/nvram/'.$uuid.'_VARS.fd','custom recovery checkpoint path normalization');
    portableReject(fn()=>unmRecoveryTransformDomainXml(str_replace('Squid Proxy</name>','Other VM</name>',$xml),[$source=>'/mnt/destination/activation/disk.img']),'custom recovery ownership mismatch');
    // A private command fixture supplies only ZFS/libvirt inventory. Real find,
    // file ownership, links and sibling validation remain exercised.
    $oldPath=getenv('PATH');makeTestDir($root.'/bin');makeTestDir($root.'/Squid Proxy');
    file_put_contents($root.'/bin/virsh',"#!/bin/sh\nexit 0\n");
    file_put_contents($root.'/bin/zfs',"#!/bin/sh\nprintf '%s\\n' 'pool/Squid Proxy'\n");
    chmod($root.'/bin/virsh',0700);chmod($root.'/bin/zfs',0700);putenv('PATH='.$root.'/bin:'.$oldPath);
    $mount=$root.'/Squid Proxy';$disk=$mount.'/vdisk.img';$vars=$mount.'/OVMF_VARS.fd';
    file_put_contents($disk,'disk');file_put_contents($vars,'variables');
    $vm=['uuid'=>$uuid,'name'=>'Squid Proxy','nvram'=>$vars,'disks'=>[['type'=>'file','source'=>$disk]]];
    portableCheck(unmReplicationDatasetIsolationErrors('pool/Squid Proxy',$mount,[$disk],$vm)===[],'owned sibling NVRAM is accepted in dedicated replication');
    file_put_contents($mount.'/unrelated','unrelated');
    portableCheck(unmReplicationDatasetIsolationErrors('pool/Squid Proxy',$mount,[$disk],$vm)!==[],'unrelated dataset file stays blocked');unlink($mount.'/unrelated');
    $vm['name']='Different VM';
    portableCheck(unmReplicationDatasetIsolationErrors('pool/Squid Proxy',$mount,[$disk],$vm)!==[],'unproven NVRAM ownership stays blocked');
    putenv('PATH='.$oldPath);
    echo "Firmware equivalence, TPM discovery, exact state cleanup and custom recovery regressions passed.\n";
}finally{
    if(isset($oldPath))putenv('PATH='.$oldPath);
    foreach(['bin/virsh','bin/zfs','Squid Proxy/vdisk.img','Squid Proxy/OVMF_VARS.fd','Squid Proxy/unrelated'] as $relative)if(is_file($root.'/'.$relative))unlink($root.'/'.$relative);
    if(isset($legacy)&&is_file($legacy))unlink($legacy);
    if(isset($escape)&&is_link($escape))unlink($escape);
    if(is_file($root.'/'.$uuid.'_VARS.fd'))unlink($root.'/'.$uuid.'_VARS.fd');
    foreach([$root.'/code.fd',$root.'/alternate.fd',$root.'/vars.fd',$root.'/wrong.fd'] as $file)if(file_exists($file))unlink($file);
    foreach(['/var/lib/libvirt/swtpm/'.$uuid,'/etc/libvirt/swtpm/'.$uuid] as $path)foreach(['tpm2-00.permall','unexpected'] as $file)if(is_file($path.'/'.$file))unlink($path.'/'.$file);
    foreach(array_reverse($created) as $dir)if(is_dir($dir))rmdir($dir);
    rmdir($root);
}
