<?php
// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
declare(strict_types=1);
namespace NormalFixture;
require __DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/lib.php';
$root=\sys_get_temp_dir().'/unmotion-normal-'.bin2hex(random_bytes(8));mkdir($root,0700);
$cleanupMock=null;$queued=0;$preview=['retained'=>[],'items'=>[['id'=>'exact-image']],'review'=>str_repeat('c',64)];
define(__NAMESPACE__.'\UNM_BOOT_DIR',$root);$checks=0;$commands=[];$failUnlink=null;$recovery=null;
$host='11111111-1111-4111-8111-000000000001';$peer='22222222-2222-4222-8222-000000000001';$uuid='74200000-1111-4111-8111-000000000006';$id='live-'.str_repeat('a',24);$boot='33333333-3333-4333-8333-000000000001';
$authority=['schemaVersion'=>1,'vmUuid'=>$uuid,'vmName'=>'Squid Proxy Normal','jobId'=>$id,'state'=>'OWNER','role'=>'destination','diskPath'=>'','sourceHostId'=>$peer,'destinationHostId'=>$host,'bootId'=>$boot,'expiresAt'=>time()+1000,'workflow'=>'ordinary'];
$job=['id'=>$id,'vmUuid'=>$uuid,'vmName'=>$authority['vmName'],'role'=>'destination','state'=>'COMPLETE','sourceHostId'=>$peer,'destinationHostId'=>$host];
$state=['state'=>'running','qemuAbsent'=>false];$q=['pid'=>5500,'startTicks'=>'99','exe'=>'/usr/bin/qemu-system-x86_64','bootId'=>$boot,'uuid'=>$uuid];$admission=['jobId'=>$id,'phase'=>'RUNNING','bootId'=>$boot,'qemu'=>$q];
$remote=['jobId'=>$id,'vmUuid'=>$uuid,'hostId'=>$peer,'authority'=>'RETIRED','bootId'=>$boot,'vm'=>['state'=>'undefined','qemuAbsent'=>true]];$caps=1;
$ownerIdentity=['device'=>'fixture-owner','inode'=>'1'];$xml='<domain><name>Squid Proxy Normal</name><uuid>'.$uuid.'</uuid><devices><disk type="file" device="disk"><source file="/mnt/cache/normal-owner.qcow2"/><target dev="vda" bus="virtio"/></disk></devices></domain>';
$src=\file_get_contents(__DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/live-normal.php');
eval('namespace NormalFixture; use \\RuntimeException; use \\Throwable;'.substr($src,strpos($src,'function unmLiveNormalPath')));
function check(bool $yes,string $why):void {global $checks;if(!$yes)throw new \RuntimeException($why);++$checks;}
function rejects(callable $f,string $why):void {try{$f();}catch(\Throwable){check(true,$why);return;}throw new \RuntimeException('Unexpected acceptance: '.$why);}
function unmNativeJson(string $p):array {$v=json_decode((string)\file_get_contents(map($p)),true,64,JSON_THROW_ON_ERROR);if(!is_array($v))throw new \RuntimeException('Invalid JSON');return $v;}
function unmNativeWrite(string $p,array $v):void {if(!is_dir(dirname($p)))mkdir(dirname($p),0700,true);\file_put_contents($p,json_encode($v,JSON_THROW_ON_ERROR));}
function unmNativeLock(string $id){return fopen(UNM_BOOT_DIR.'/test-native.lock','c+');}
function unmLivePrivateDir(string $p):void {if(!is_dir($p))mkdir($p,0700,true);}
function unmHostId():string {global $host;return $host;}
function unmNativeBoot():string {global $boot;return $boot;}
function unmLiveJobPath(string $job):string {return UNM_BOOT_DIR.'/jobs/'.$job.'/job.json';}
function unmLiveJob(string $id):array {global $job;return $job;}
function unmLiveStoreJob(array $j):void {global $job;$job=$j;}
function unmLiveNativeRecord(string $base,string $uuid):?array {
    $p=$base.'/live-authority/'.$uuid.'.json';$m=$base.'/live-managed/'.$uuid.'.json';if(!file_exists($p)&&!file_exists($m))return null;if(!is_file($p)||!is_file($m))throw new \RuntimeException('Incomplete pair');$r=unmNativeJson($p);if($r['jobId']!==(unmNativeJson($m)['jobId']??null))throw new \RuntimeException('Wrong marker');return $r;
}
function unmLiveCommand(array $args,?string $input=null,int $seconds=20):string {global $commands,$xml;$commands[]=$args;if($args[0]==='sync'||array_slice($args,0,2)===['virsh','autostart'])return '';if(array_slice($args,0,2)===['virsh','dumpxml'])return $xml;throw new \RuntimeException('Unexpected VM mutation/command');}
function unmNativeRecordForVm(string $base,string $uuid):?array {global $recovery;return $recovery;}
function unmLiveVmState(string $uuid):array {global $state;return $state;}
function unmLiveCleanupInterlock(string $uuid):void {}
function unmLiveFenceProof():void {}
function unmLiveNoJob(string $uuid,array $state):void {}
function unmLiveVmLock(string $uuid){return fopen('php://temp','r+');}
function unmProcessRecordedWorker(string $p,string $script,array $args=[]):?array {return null;}
function unmLiveCleanupRecord(string $id):?array {global $cleanupMock;return $cleanupMock;}
function unmLiveCleanupPreview(string $id):array {global $preview;return $preview;}
function unmLiveCleanupStart(string $id,string $review):array {global $queued;++$queued;return ['success'=>true];}
function unmNativeQemu(string $uuid,string $name):array {global $q;return $q;}
function unmNativeDomain(string $xml):array {return \unmNativeDomain($xml);}
function unmLiveNativeBinding(array $d,array $r):void {\unmLiveNativeBinding($d,$r);}
function unmLiveCleanupFileIdentity(string $p,bool $dir=false):array {global $ownerIdentity;return $ownerIdentity;}
function unmLivePeerForJob(array $j):array {global $peer;return ['hostId'=>$peer];}
function unmRemote(array $p,string $command,int $seconds):array {global $caps;return ['code'=>0,'stdout'=>json_encode(['hostId'=>$p['hostId'],'protocolVersion'=>7,'liveNormalVersion'=>$caps])];}
function unmLiveRpc(array $p,string $action,array $args,int $seconds=30):array {global $remote;if($action!=='status')throw new \RuntimeException('Unexpected RPC');return $remote;}
function unmInventoryCache(){return new class{function invalidate():void {}};}
function file_exists(string $p):bool {return \file_exists(map($p));}
function is_file(string $p):bool {return \is_file(map($p));}
function is_link(string $p):bool {return \is_link(map($p));}
function map(string $p):string {global $uuid;return $p==='/run/unmotion/lifecycle/live-admissions/'.$uuid.'.json'?UNM_BOOT_DIR.'/admission.json':$p;}
function unlink(string $p):bool {global $failUnlink;$p=map($p);if($failUnlink!==null&&str_contains($p,$failUnlink)){$failUnlink=null;throw new \RuntimeException('Injected unlink interruption');}return \unlink($p);}
function putOwner(array $a):void {global $uuid;unmNativeWrite(UNM_BOOT_DIR.'/live-authority/'.$uuid.'.json',$a);unmNativeWrite(UNM_BOOT_DIR.'/live-managed/'.$uuid.'.json',['schemaVersion'=>1,'vmUuid'=>$uuid,'jobId'=>$a['jobId']]);}
function resetCase():void {global $root,$authority,$uuid,$admission,$job,$id,$state,$q,$remote,$peer,$host,$boot,$caps,$recovery,$commands;
    foreach([UNM_BOOT_DIR.'/live-normal/'.$uuid.'.json',UNM_BOOT_DIR.'/live-authority/'.$uuid.'.json',UNM_BOOT_DIR.'/live-managed/'.$uuid.'.json',UNM_BOOT_DIR.'/admission.json'] as $p)if(\is_file($p))\unlink($p);
    $job=['id'=>$id,'vmUuid'=>$uuid,'vmName'=>$authority['vmName'],'role'=>'destination','state'=>'COMPLETE','sourceHostId'=>$peer,'destinationHostId'=>$host];$state=['state'=>'running','qemuAbsent'=>false];$q=['pid'=>5500,'startTicks'=>'99','exe'=>'/usr/bin/qemu-system-x86_64','bootId'=>$boot,'uuid'=>$uuid];$admission=['jobId'=>$id,'phase'=>'RUNNING','bootId'=>$boot,'qemu'=>$q];$remote=['jobId'=>$id,'vmUuid'=>$uuid,'hostId'=>$peer,'authority'=>'RETIRED','bootId'=>$boot,'vm'=>['state'=>'undefined','qemuAbsent'=>true]];$caps=1;$recovery=null;$commands=[];putOwner($authority);unmNativeWrite(UNM_BOOT_DIR.'/admission.json',$admission);
}
// Route admission reads into this fixture, not the real /run filesystem.
function file_get_contents(string $p):string|false {return \file_get_contents(map($p));}
// unmNativeJson also maps runtime paths.
function cleanTree(string $p):void {foreach(scandir($p)?:[] as $n){if($n==='.'||$n==='..')continue;$c=$p.'/'.$n;is_dir($c)&&!\is_link($c)?cleanTree($c):\unlink($c);}rmdir($p);}
try{
    resetCase();$before=$q;$receipt=unmLiveNormalComplete($job);check($receipt['phase']==='NORMAL','Completed owner becomes normal');check(unmLiveNativeRecord(UNM_BOOT_DIR,$uuid)===null,'No active owner marker or authority remains');check($q===$before,'Original QEMU untouched');check(unmLiveNormalCurrent($uuid)['jobId']===$id,'Receipt is provenance only');
    check(unmLiveNormalComplete($job)===$receipt,'Completion retry is idempotent');check(unmLiveNormalCleanupProof($job)['normalOwner']===true,'Normal VM can endorse old-copy cleanup');
    $xml=str_replace('Squid Proxy Normal','Squid Proxy Renamed',$xml);check(unmLiveNormalCleanupProof($job)['normalOwner']===true,'Normal rename/hardware edits do not need old live contract');
    $oldXml=$xml;$xml=str_replace('normal-owner.qcow2','changed-owner.qcow2',$xml);rejects(fn()=>unmLiveNormalCleanupProof($job),'Storage edits cannot reuse an old cleanup proof');$xml=$oldXml;$ownerIdentity['inode']='2';rejects(fn()=>unmLiveNormalCleanupProof($job),'Replaced normal-owner disk cannot reuse cleanup proof');$ownerIdentity['inode']='1';
    $state=['state'=>'undefined','qemuAbsent'=>true];rejects(fn()=>unmLiveNormalCleanupProof($job),'Absent normal VM cannot endorse cleanup');$state=['state'=>'running','qemuAbsent'=>false];$recovery=['side'=>'source'];rejects(fn()=>unmLiveNormalCleanupProof($job),'Separate recovery authority is not guessed');$recovery=null;
    foreach(['jobId','vmUuid','hostId','authority','vm'] as $key){resetCase();$remote[$key]=$key==='vm'?['state'=>'running','qemuAbsent'=>false]:'wrong';rejects(fn()=>unmLiveNormalComplete($job),'Exact paired absence required: '.$key);check(unmLiveNativeRecord(UNM_BOOT_DIR,$uuid)===$authority,'Rejection keeps owner records');}
    foreach(['pid','startTicks','exe','bootId','uuid'] as $key){resetCase();$q[$key]=$key==='pid'?5501:'wrong';rejects(fn()=>unmLiveNormalComplete($job),'No QEMU adoption: '.$key);}
    resetCase();$caps=0;rejects(fn()=>unmLiveNormalComplete($job),'Old peer rejected before release');
    foreach(['COPYING','COMMITTING','ATTENTION','FAILED','CANCELLED'] as $s){resetCase();$job['state']=$s;rejects(fn()=>unmLiveNormalComplete($job),'No incoming owner release from '.$s);}
    foreach(['RETIRED','RECEIVING','ABORTED','MOVING'] as $s){resetCase();$bad=$authority;$bad['state']=$s;putOwner($bad);rejects(fn()=>unmLiveNormalComplete($job),'Non-owner/in-flight authority kept: '.$s);}
    resetCase();$failUnlink='/live-managed/';rejects(fn()=>unmLiveNormalRelease($authority,'live',$id,$peer,$remote),'Crash between exact owner unlinks');check(unmLiveNormalRead($uuid)['phase']==='RELEASING','Durable intent survives interruption');rejects(fn()=>unmLiveNativeRecord(UNM_BOOT_DIR,$uuid),'Partial owner pair fails closed');$done=unmLiveNormalRelease($authority,'live',$id,$peer,$remote);check($done['phase']==='NORMAL','Exact hash-bound release resumes');
    resetCase();$failUnlink='/live-managed/';rejects(fn()=>unmLiveNormalRelease($authority,'live',$id,$peer,$remote),'Second injected interruption');$new=$authority;$new['jobId']='live-'.str_repeat('b',24);putOwner($new);rejects(fn()=>unmLiveNormalRelease($authority,'live',$id,$peer,$remote),'Old release cannot remove new authority');check(unmLiveNativeRecord(UNM_BOOT_DIR,$uuid)===$new,'New generation remains intact');
    resetCase();$job['sourceAutostart']=true;unmLiveNormalComplete($job);check(in_array(['virsh','autostart',$uuid],$commands,true),'Autostart choice restored');foreach($commands as $args)check(!in_array($args[1]??'',['start','destroy','shutdown','resume','reboot'],true),'No guest power mutation during release');
    $job['role']='source';unmLiveNormalFinish($id);check($queued===0,'Legacy jobs are not retroactively auto-deleted');$job['normalCompletionVersion']=1;unmLiveNormalFinish($id);check($queued===1,'New successful source queues exact reviewed cleanup');
    foreach(['FAILED','CANCELLED','ATTENTION'] as $s){$job['state']=$s;unmLiveNormalFinish($id);check($queued===1,'Failed/unresolved copies not automatically discarded: '.$s);}$job['state']='COMPLETE';
    $job['automaticCleanupError']='ownership unavailable';unmLiveNormalFinish($id);check($queued===1,'Blocked automatic cleanup needs explicit review');unset($job['automaticCleanupError']);
    $cleanupMock=['state'=>'COMPLETE'];unmLiveNormalFinish($id);check($queued===1,'Completed cleanup is not requeued');$cleanupMock=['state'=>'BLOCKED'];unmLiveNormalFinish($id);check($queued===1,'Blocked journal is not silently retried');$cleanupMock=null;
    $preview['retained']=[['path'=>'unknown legacy path']];rejects(fn()=>unmLiveNormalFinish($id),'Unproven data is not adopted for automatic cleanup');check($queued===1,'Unknown objects queue no deletion');
    echo 'Normal live completion, exact release/replay, original QEMU, ordinary controls and cleanup proof passed ('.$checks.").\n";
}finally{cleanTree($root);}
