<?php
// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
declare(strict_types=1);
require_once __DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/lib.php';
function check(bool $condition,string $message): void {if(!$condition)throw new RuntimeException($message);}
function reject(callable $call): void {try{$call();}catch(RuntimeException $e){return;}throw new RuntimeException('Unsafe cache input accepted.');}
$dir=sys_get_temp_dir().'/unmotion-inventory-'.bin2hex(random_bytes(8));mkdir($dir,0700);
try {
    $cache=new UnmInventoryCache($dir,'boot-one','0.4.5');$now=time();
    $empty=$cache->view('definition',$now);check(!$empty['inventory']['available'],'Cold cache is loading, not an empty host.');
    $vms=[['uuid'=>'vm-one','name'=>'Squid Proxy','detailsPending'=>true]];
    $cache->publish($vms,false,'definition','',$now);
    $view=$cache->view('definition',$now);check($view['inventory']['available']&&!$view['inventory']['complete']&&$view['vms']===$vms,'Basic rows precede details.');
    $cache->publish($vms,true,'definition','',$now);
    check(!$cache->view('definition',$now)['inventory']['stale'],'Fresh cache reused.');
    check($cache->view('definition',$now+30)['inventory']['stale'],'TTL expires.');
    check($cache->view('new-definition',$now)['inventory']['stale'],'Definition edits invalidate.');
    check($cache->view('definition',$now-1)['inventory']['stale'],'Clock reversal invalidates.');
    check(!(new UnmInventoryCache($dir,'boot-two','0.4.5'))->view('definition',$now)['inventory']['available'],'Previous boot is rejected.');
    check(!(new UnmInventoryCache($dir,'boot-one','0.4.6'))->view('definition',$now)['inventory']['available'],'Previous package schema is not adopted.');
    $cache->invalidate();check($cache->view('definition',$now)['inventory']['stale'],'Lifecycle change invalidates.');
    $lock=$cache->lock('worker.lock');check(is_resource($lock),'First worker owns lock.');
    check($cache->lock('worker.lock')===null,'Second worker rejected.');
    check($cache->view('definition',$now)['inventory']['refreshing'],'Running scan reported.');
    $launches=0;$cache->request('definition',false,function()use(&$launches){++$launches;});
    check($launches===0,'No duplicate collector while lock is held.');fclose($lock);
    $cache->request('definition',false,function()use(&$launches){++$launches;});
    $cache->request('definition',false,function()use(&$launches){++$launches;});
    check($launches===1,'Cross-tab launch gap is throttled.');
    $cache->write('status.json',['error'=>'VM Manager unavailable','attemptAt'=>time()]);
    $view=$cache->view('definition',$now);check($view['vms']===$vms&&$view['inventory']['error']==='VM Manager unavailable','Failed refresh retains rows.');
    $cache->request('definition',false,function()use(&$launches){++$launches;});check($launches===1,'Failure backoff prevents fork storms.');
    $cache->request('definition',true,function(){throw new RuntimeException('Launcher failure');});
    check($cache->view('definition',time())['inventory']['error']==='Launcher failure','Launch errors are visible.');
    foreach(glob($dir.'/*')?:[] as $file)check((fileperms($file)&0777)===0600,'Cache is private RAM state.');
    file_put_contents($dir.'/inventory.json','{bad');check(!$cache->view('definition',time())['inventory']['available'],'Corrupt observation rejected.');
    unlink($dir.'/inventory.json');symlink($dir.'/outside',$dir.'/inventory.json');reject(fn()=>$cache->view('definition',time()));unlink($dir.'/inventory.json');
    $linked=$dir.'/link';symlink($dir,$linked);reject(fn()=>new UnmInventoryCache($linked,'b','v'));unlink($linked);
    chmod($dir,0777);reject(fn()=>new UnmInventoryCache($dir,'b','v'));chmod($dir,0700);
    $xml='<domain><name>Squid Proxy</name><uuid>12345678-1234-1234-1234-123456789abc</uuid><memory unit="MiB">512</memory><vcpu>2</vcpu></domain>';
    $basic=unmInventoryBasicVm('12345678-1234-1234-1234-123456789abc',$xml,"State: shut off\nAutostart: disable\n");
    check($basic['name']==='Squid Proxy'&&$basic['vcpus']===2&&$basic['memoryMiB']===512&&$basic['state']==='shut off','Basic fields and spaces preserved.');
    reject(fn()=>unmInventoryBasicVm('wrong-uuid',$xml,''));
    check(unmDatasetUsedByOtherVm('/mnt/cache/Squid Proxy','one',['one'=>$xml,'two'=>null]),'Unknown sibling XML cannot prove isolation.');
    check(!unmDatasetUsedByOtherVm('/mnt/cache/Squid Proxy','one',['one'=>$xml,'two'=>$xml]),'Display index reuses XML without virsh.');
    $lib=file_get_contents(__DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/lib.php');
    $inventory=substr($lib,strpos($lib,'function unmInventory():'),strpos($lib,'function unmNewUuid():')-strpos($lib,'function unmInventory():'));
    check(str_contains($inventory,'unmParseVm($uuid)')&&!str_contains($inventory,'Cache'),'Operational inventory remains fresh.');
    $transport=file_get_contents(__DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/transport.php');
    check(str_contains($transport,"'inventory-view'")&&str_contains($transport,'&&$count===2'),'Display RPC has exact bounded arguments.');
    echo "Inventory cache, TTL, invalidation, outage, locking, privacy and fresh-operation regressions passed.\n";
} finally {foreach(glob($dir.'/*')?:[] as $path){if(is_file($path)||is_link($path))unlink($path);}rmdir($dir);}
