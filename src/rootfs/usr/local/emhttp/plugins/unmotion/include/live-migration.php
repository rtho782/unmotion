<?php
// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
declare(strict_types=1);
require_once __DIR__.'/live-rpc.php';

const UNM_LIVE_SCHEMA=1;
const UNM_LIVE_TRANSFER_SECONDS=1800;
const UNM_LIVE_TRANSPORT_GRACE_SECONDS=120;
require_once __DIR__.'/live-native-stream.php';
function unmLiveId(string $id): string {if(!preg_match('/\Alive-[a-f0-9]{24}\z/D',$id))throw new InvalidArgumentException('Invalid live migration identity.');return $id;}
function unmLiveRoot(): string {return UNM_BOOT_DIR.'/live-migration';}
function unmLiveRuntime(string $id): string {return '/run/unmotion/live/'.unmLiveId($id);}
function unmLiveJobPath(string $id): string {return unmLiveRoot().'/jobs/'.unmLiveId($id).'/job.json';}
function unmLiveJob(string $id): array {
    $path=unmLiveJobPath($id);if(is_link($path)||!is_file($path))throw new RuntimeException('Live migration job is unavailable.');
    for($parent=dirname($path);$parent!==dirname($parent);$parent=dirname($parent))if(is_link($parent))throw new RuntimeException('Live job directory is redirected.');
    $j=json_decode((string)file_get_contents($path),true,64,JSON_THROW_ON_ERROR);
    $uuid='/\A[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\z/D';
    if(!is_array($j)||($j['schemaVersion']??0)!==UNM_LIVE_SCHEMA||($j['id']??'')!==$id||!preg_match($uuid,(string)($j['vmUuid']??''))||!preg_match($uuid,(string)($j['sourceHostId']??''))||!preg_match($uuid,(string)($j['destinationHostId']??''))||($j['sourceHostId']??'')===($j['destinationHostId']??'')||!in_array($j['role']??'',['source','destination'],true)||($j['runtime']??'')!==unmLiveRuntime($id)||!is_int($j['expiresAt']??null)||!preg_match('/\A[a-f0-9]{64}\z/D',(string)($j['tokenHash']??'')))throw new RuntimeException('Live migration job identity is corrupt.');
    return $j;
}
function unmLivePrivateDir(string $dir): void {
    for($parent=$dir;$parent!==dirname($parent);$parent=dirname($parent))if(is_link($parent))throw new RuntimeException('Redirected live migration directory.');
    if(is_link($dir)||(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir)))throw new RuntimeException('Live migration directory is unavailable.');
    @chmod($dir,0700);if(fileowner($dir)!==0)throw new RuntimeException('Live migration directory is not root-owned.');
}
require_once __DIR__.'/live-engine.php';
require_once __DIR__.'/live-zvol.php';
require_once __DIR__.'/live-progress.php';
require_once __DIR__.'/live-unreserved.php';
require_once __DIR__.'/live-completion.php';
function unmLiveStoreJob(array $job): void {unmLiveId($job['id']);unmLivePrivateDir(dirname(unmLiveJobPath($job['id'])));unmAtomicJson(unmLiveJobPath($job['id']),$job);}
function unmLivePeerForJob(array $job): array {
    $host=$job[$job['role']==='source'?'destinationHostId':'sourceHostId'];$matches=array_values(array_filter(unmPeers(),static fn($p)=>($p['pairingState']??'')==='paired'&&($p['hostId']??'')===$host));
    if(count($matches)!==1)throw new RuntimeException('Exactly one current pairing for the recorded host identity is required.');return $matches[0];
}
function unmLiveVmLock(string $uuid) {
    $uuid=unmTransportUuid($uuid);$h=fopen('/var/lock/unmotion-'.$uuid.'.lock','ce');if(!$h||!flock($h,LOCK_EX|LOCK_NB))throw new RuntimeException('Another unMotion operation is using this VM.');return $h;
}
function unmLiveAssertWorkerGone(string $id,?array $snapshots=null): void {
    if($snapshots===null){$snapshots=[];foreach(glob('/proc/[0-9]*/stat')?:[] as $path){$p=unmProcessSnapshot((int)basename(dirname($path)));if($p)$snapshots[]=$p;}}
    foreach($snapshots as $p)if(unmProcessCommandMatches($p,'/usr/local/sbin/unmotion-live-worker',[$id]))throw new RuntimeException('A live worker for this job is still present; cancellation or reconciliation must wait.');
}
function unmLiveAlive(array $job): bool {
    try{$current=unmLiveJob($job['id']);$authority=unmLiveNativeRecord(UNM_BOOT_DIR,$current['vmUuid']);return ($authority['jobId']??'')===$current['id']&&($authority['bootId']??'')===unmNativeBoot()&&($authority['role']??'')===$current['role']&&in_array($authority['state']??'',['MOVING','RECEIVING'],true)&&($current['bootId']??'')===unmNativeBoot()&&(int)($current['expiresAt']??0)>time()&&in_array($current['state']??'',['DESTINATION_READY','COPYING','RECEIVED_PAUSED','SOURCE_MOVING'],true);}catch(Throwable){return false;}
}
function unmLiveStreamAuthorize(array $request,array $peer,string $kind): array {
    if(array_diff(array_keys($request),['jobId','token','channel']))throw new RuntimeException('Unknown live transport option.');
    $j=unmLiveJob((string)($request['jobId']??''));$token=$request['token']??'';
    if(($peer['pairingState']??'')!=='paired'||($j['role']??'')!=='destination'||($j['sourceHostId']??'')!==$peer['hostId']||($j['destinationHostId']??'')!==unmHostId()||!is_string($token)||!preg_match('/\A[a-f0-9]{64}\z/D',$token)||!hash_equals((string)($j['tokenHash']??''),hash('sha256',$token))||!unmLiveAlive($j))throw new RuntimeException('Live transport session is not authorized.');
    if($kind==='data'&&!in_array($request['channel']??'',['memory','disk'],true))throw new RuntimeException('Unknown live data channel.');
    if($kind!=='data'&&isset($request['channel']))throw new RuntimeException('Unexpected control channel.');return $j;
}
function unmLiveSshArgs(array $peer,array $request,string $operation): array {
    unmAssertRemotePeerHost($peer['host']);if(($peer['username']??'root')!=='root')throw new RuntimeException('Live migration requires the paired root account.');
    $key=$peer['keyPath'];if(in_array($operation,['live-data','live-benchmark'],true)){$j=unmLiveJob((string)($request['jobId']??''));if($j['role']!=='source'||($j['destinationHostId']??'')!==$peer['hostId'])throw new RuntimeException('Wrong data-key peer.');unmLiveDataKeyCheck($j);$key=$j['runtime'].'/data-key';if(is_link($key)||!is_file($key))throw new RuntimeException('Private disposable key is unavailable.');}
    return ['/usr/bin/ssh','-F','/dev/null','-T','-p',(string)$peer['port'],'-i',$key,'-o','ControlMaster=no','-o','ControlPath=none','-o','BatchMode=yes','-o','Compression=no','-o','IdentitiesOnly=yes','-o','IdentityAgent=none','-o','ClearAllForwardings=yes','-o','ConnectTimeout=10','-o','ServerAliveInterval=15','-o','ServerAliveCountMax=3','-o','StrictHostKeyChecking=yes','-o','UserKnownHostsFile='.$peer['knownHosts'],'-o','GlobalKnownHostsFile=/dev/null','-o','UpdateHostKeys=no','root@'.$peer['host'],unmTransportEnvelope($operation,$request)];
}
function unmLiveLog(string $id,string $message): void {
    $dir=dirname(unmLiveJobPath($id));if(is_dir($dir))file_put_contents($dir.'/live.log',gmdate('c').' '.$message."\n",FILE_APPEND|LOCK_EX);
}
/** Native NBD may open one connection per admitted disk plus a probe. */
function unmLiveDataLimit(array $job,string $channel): int {
    if($channel==='memory')return 1;if($channel!=='disk')throw new RuntimeException('Invalid live data channel.');
    $count=isset($job['disks'])?count(unmLiveDisks($job)):1;return $count+1;
}
function unmLiveDataLock(array $job,string $runtime,string $channel): array {
    $limit=unmLiveDataLimit($job,$channel);
    for($slot=0;$slot<$limit;$slot++){$name=$channel==='memory'?'memory':'disk-'.$slot;$path=$runtime.'/'.$name.'.lock';if(is_link($path))throw new RuntimeException('Redirected migration channel lock.');$h=fopen($path,'ce');if(!$h)throw new RuntimeException('Cannot open migration channel admission.');if(flock($h,LOCK_EX|LOCK_NB))return [$h,$name];fclose($h);}
    throw new RuntimeException('All admitted migration data slots are in use.');
}
function unmLiveReceivedBytes(array $job): int {
    $runtime=unmLiveRuntime($job['id']);$paths=[$runtime.'/received-memory.json'];$slots=[];
    for($i=0;$i<unmLiveDataLimit($job,'disk');$i++)if(is_file($runtime.'/received-disk-'.$i.'.json'))$slots[]=$runtime.'/received-disk-'.$i.'.json';
    $paths=array_merge($paths,$slots?:[$runtime.'/received-disk.json']);$total=0;
    foreach($paths as $path){$bytes=unmLoadJson($path)['bytes']??null;if(is_int($bytes)&&$bytes>=0&&$bytes<=PHP_INT_MAX-$total)$total+=$bytes;}return $total;
}
/** Optional telemetry must never emit a warning into the binary data channel. */
function unmLiveObserveReceived(string $runtime,string $channel,int $bytes): bool {
    if(!preg_match('/\A(?:memory|disk|disk-(?:[0-9]|1[0-6]))\z/D',$channel)||$bytes<0)return false;
    set_error_handler(static function(){throw new RuntimeException('Receive progress storage is unavailable.');});
    try{unmNativeWrite($runtime.'/received-'.$channel.'.json',['bytes'=>$bytes,'observedAt'=>gmdate('c')]);return true;}
    catch(Throwable){return false;}finally{restore_error_handler();}
}

/** An admitted NBD disk may be idle throughout the other disks/RAM transfer.
 * Bound total channel lifetime, not its idle time; authority is checked separately
 * on every pump iteration. Allow only cleanup grace beyond the worker's limit.
 */
function unmLiveDataWithinWindow(int $openedAt,int $now): bool {
    return $now>=$openedAt&&$now-$openedAt<=UNM_LIVE_TRANSFER_SECONDS+UNM_LIVE_TRANSPORT_GRACE_SECONDS;
}
function unmLiveRelay(string $id,string $channel): void {unmLiveNativeRelay($id,$channel);}
