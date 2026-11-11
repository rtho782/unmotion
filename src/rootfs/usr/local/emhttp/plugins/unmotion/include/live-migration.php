<?php
// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
declare(strict_types=1);
require_once __DIR__.'/live-rpc.php';

const UNM_LIVE_SCHEMA=1;
const UNM_LIVE_TRANSFER_SECONDS=1800;
const UNM_LIVE_TRANSPORT_GRACE_SECONDS=120;
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
    return ['ssh','-T','-p',(string)$peer['port'],'-i',$peer['keyPath'],'-o','BatchMode=yes','-o','Compression=no','-o','IdentitiesOnly=yes','-o','IdentityAgent=none','-o','ClearAllForwardings=yes','-o','ConnectTimeout=10','-o','ServerAliveInterval=15','-o','ServerAliveCountMax=3','-o','StrictHostKeyChecking=yes','-o','UserKnownHostsFile='.$peer['knownHosts'],'-o','GlobalKnownHostsFile=/dev/null','-o','UpdateHostKeys=no','root@'.$peer['host'],unmTransportEnvelope($operation,$request)];
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
function unmLiveRawPump($input,$output,$backend,callable $alive,?callable $progress=null): void {
    foreach([$input,$output,$backend] as $s)stream_set_blocking($s,false);$out='';$in='';$a=true;$b=true;$openedAt=time();
    $halfClosed=false;$received=0;$reported=0;
    while($a||$b||$out!==''||$in!==''){
        if(!$alive()||!unmLiveDataWithinWindow($openedAt,time()))throw new RuntimeException('Live data lease expired.');
        $r=[];if($a&&strlen($out)<1048576)$r[]=$input;if($b&&strlen($in)<1048576)$r[]=$backend;$w=[];if($out!=='')$w[]=$backend;if($in!=='')$w[]=$output;$e=[];
        if(!$r&&!$w)break;if(@stream_select($r,$w,$e,1)===false)throw new RuntimeException('Live data stream failed.');
        foreach($r as $s){$v=fread($s,262144);if($v===false)throw new RuntimeException('Live data read failed.');if($v===''){if(feof($s)){if($s===$input)$a=false;else$b=false;}}else{if($s===$input){$out.=$v;$received+=strlen($v);}else$in.=$v;}}
        if($progress&&time()>$reported){$reported=time();$progress($received);}
        foreach($w as $s){if($s===$backend){$n=fwrite($s,$out);if($n===false)throw new RuntimeException('Live data write failed.');$out=substr($out,$n);}else{$n=fwrite($s,$in);if($n===false)throw new RuntimeException('Live data write failed.');$in=substr($in,$n);}}
        if(!$a&&$out===''&&!$halfClosed){stream_socket_shutdown($backend,STREAM_SHUT_WR);$halfClosed=true;}
        if(!$b){if($out!=='')throw new RuntimeException('Live backend closed before input was delivered.');if($in==='')break;}
    }
    if($progress)$progress($received);
}

/** Local listeners forward only the two predetermined per-job Unix sockets. */
function unmLiveRelay(string $id,string $channel): void {
    if(!in_array($channel,['memory','disk'],true))throw new RuntimeException('Invalid relay.');
    $j=unmLiveJob($id);if($j['role']!=='source'||!unmLiveAlive($j))throw new RuntimeException('Inactive source relay.');
    $runtime=unmLiveRuntime($id);unmLivePrivateDir($runtime);$path=$runtime.'/'.$channel.'.sock';if(file_exists($path)||is_link($path))throw new RuntimeException('Relay socket already exists.');
    $listener=stream_socket_server('unix://'.$path,$error,$text);if(!$listener)throw new RuntimeException('Cannot bind relay socket: '.$text);chmod($path,0600);stream_set_blocking($listener,false);
    $token=trim((string)file_get_contents($runtime.'/token'));$peer=unmLivePeerForJob($j);$connections=[];
    try{
        while(unmLiveAlive($j)){
            $r=[$listener];$w=[];$e=[];
            foreach($connections as $c){if($c['localOpen']&&strlen($c['toRemote'])<1048576)$r[]=$c['socket'];if($c['remoteOpen']&&strlen($c['toLocal'])<1048576)$r[]=$c['pipes'][1];if(is_resource($c['pipes'][0])&&$c['toRemote']!=='')$w[]=$c['pipes'][0];if($c['toLocal']!=='')$w[]=$c['socket'];}
            if(@stream_select($r,$w,$e,1)===false)throw new RuntimeException('Relay select failed.');
            if(in_array($listener,$r,true)){
                $client=@stream_socket_accept($listener,0);if($client){
                    if(count($connections)>=unmLiveDataLimit($j,$channel)){fclose($client);throw new RuntimeException('Too many migration data connections.');}
                    $argv=unmLiveSshArgs($peer,['jobId'=>$id,'token'=>$token,'channel'=>$channel],'live-data');
                    $process=proc_open($argv,[0=>['pipe','r'],1=>['pipe','w'],2=>['file',dirname(unmLiveJobPath($id)).'/transport.log','a']],$pipes);
                    if(!is_resource($process)){fclose($client);throw new RuntimeException('Cannot open encrypted data transport.');}
                    foreach([$client,$pipes[0],$pipes[1]] as $s)stream_set_blocking($s,false);
                    $connections[]=['socket'=>$client,'pipes'=>$pipes,'process'=>$process,'identity'=>unmProcessSnapshot(proc_get_status($process)['pid']),'toRemote'=>'','toLocal'=>'','localOpen'=>true,'remoteOpen'=>true];
                }
            }
            foreach($connections as $key=>&$c){
                $closed=false;
                foreach([$c['socket'],$c['pipes'][1]] as $s)if(in_array($s,$r,true)){$v=fread($s,262144);if($v===false)throw new RuntimeException('Relay read failed.');if($v===''&&feof($s)){if($s===$c['socket'])$c['localOpen']=false;else$c['remoteOpen']=false;}if($s===$c['socket'])$c['toRemote'].=$v;else$c['toLocal'].=$v;}
                if(in_array($c['pipes'][0],$w,true)&&$c['toRemote']!==''){$n=fwrite($c['pipes'][0],$c['toRemote']);if($n===false)$closed=true;else$c['toRemote']=substr($c['toRemote'],$n);}
                if(in_array($c['socket'],$w,true)&&$c['toLocal']!==''){$n=fwrite($c['socket'],$c['toLocal']);if($n===false)$closed=true;else$c['toLocal']=substr($c['toLocal'],$n);}
                if(!$c['localOpen']&&$c['toRemote']===''&&is_resource($c['pipes'][0]))fclose($c['pipes'][0]);
                if(!$c['remoteOpen']&&$c['toLocal']==='')$closed=true;
                if($closed){foreach([$c['socket'],$c['pipes'][0],$c['pipes'][1]] as $s)if(is_resource($s))fclose($s);if($c['identity'])unmProcessStop($c['identity'],1000);proc_close($c['process']);unset($connections[$key]);}
            }unset($c);
        }
    }finally{
        foreach($connections as $c){foreach([$c['socket'],$c['pipes'][0],$c['pipes'][1]] as $s)if(is_resource($s))fclose($s);if($c['identity'])unmProcessStop($c['identity'],1000);proc_close($c['process']);}
        fclose($listener);if(file_exists($path)&&filetype($path)==='socket')unlink($path);
    }
}
