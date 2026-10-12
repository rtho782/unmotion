<?php
// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
declare(strict_types=1);
namespace InventoryCollectorFixture;
require_once __DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/inventory-cache.php';
$source=file_get_contents(__DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/inventory-cache.php');
eval('namespace InventoryCollectorFixture; use \\Throwable; use \\RuntimeException;'.substr($source,strpos($source,'function unmInventoryCollect():')));
$dir=sys_get_temp_dir().'/unmotion-collector-'.bin2hex(random_bytes(8));mkdir($dir,0700);
$cache=new \UnmInventoryCache($dir,'test-boot','test-version');$calls=[];$fail=false;$invalidated=false;
$ids=['11111111-1111-4111-8111-111111111111','22222222-2222-4222-8222-222222222222'];
function check(bool $ok,string $message):void{if(!$ok)throw new \RuntimeException($message);}
function unmInventoryCache(){global $cache;return $cache;}
function unmInventoryDefinitionFingerprint(){return 'definition';}
function unmRun($args,$stdin,$timeout){global $calls,$fail,$ids;$calls[]=$args;if($args[1]==='list')return ['code'=>$fail?1:0,'stdout'=>implode("\n",$ids),'stderr'=>$fail?'Unavailable':''];check($args[1]==='dominfo','Only one basic info call per VM.');return ['code'=>0,'stdout'=>'info','stderr'=>''];}
function unmDomainXml($uuid,$inactive){global $calls;$calls[]=['xml',$uuid];return 'XML-'.$uuid;}
function unmInventoryBasicVm($uuid,$xml,$info){return ['uuid'=>$uuid,'name'=>'Squid Proxy '.$uuid,'state'=>'shut off','detailsPending'=>true];}
function unmParseVm($uuid,$observation){
    global $cache,$ids,$invalidated;
    $view=$cache->view('definition',time());
    check(count($view['vms'])===2,'All basic rows published before slow details.');
    check($view['inventory']['refreshing']&&!$view['inventory']['complete'],'Partial state remains refreshing.');
    check(array_keys($observation['xmlIndex'])===$ids,'One immutable XML index is shared by every detail inspection.');
    if(!$invalidated){$cache->invalidate();$invalidated=true;}
    return ['uuid'=>$uuid,'name'=>'Squid Proxy '.$uuid];
}
try{
    unmInventoryCollect();$view=$cache->view('definition',time());
    check($view['inventory']['complete']&&count($view['vms'])===2,'Completed inventory published.');
    check($view['inventory']['stale'],'Event arriving mid-scan is not overwritten/lost.');
    check(count($calls)===5,'Basic inventory is linear: one list and two calls per VM.');
    $lock=$cache->lock('worker.lock');unmInventoryCollect();check(count($calls)===5,'Duplicate collector performs no work.');fclose($lock);
    $fail=true;unmInventoryCollect();$view=$cache->view('definition',time());
    check(count($view['vms'])===2&&str_contains($view['inventory']['error'],'Unavailable'),'Outage retains last successful rows and reports error.');
    $fail=false;$ids=[];unmInventoryCollect();$view=$cache->view('definition',time());
    check($view['inventory']['complete']&&$view['vms']===[]&&$view['inventory']['error']==='','Only a successful empty scan clears rows.');
    echo "Collector progressive publication, linear calls, singleton, mid-scan events, outage retention and empty inventory passed.\n";
}finally{foreach(glob($dir.'/*')?:[] as $file)unlink($file);rmdir($dir);}
