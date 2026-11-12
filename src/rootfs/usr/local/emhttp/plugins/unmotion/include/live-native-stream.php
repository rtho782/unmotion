<?php
// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
declare(strict_types=1);
require_once __DIR__.'/live-data-keys.php';
function unmLiveNativeDataVersion(): int {
    $path='/usr/local/sbin/unmotion-live-stream';clearstatcache(true,$path);
    if(is_link($path)||!is_executable($path)||fileowner($path)!==0||(fileperms($path)&0022)!==0)return 0;
    $r=unmRun([$path,'--version'],null,5);$v=json_decode($r['stdout'],true);return $r['code']===0&&($v['version']??'')===UNM_VERSION&&($v['liveDataTransportVersion']??null)===1?1:0;
}
function unmLiveChildIdentity($process,array $argv): array {
    $pid=proc_get_status($process)['pid'];for($i=0;$i<100;$i++){$s=unmProcessSnapshot($pid);if($s&&($s['executable']??'')===$argv[0]&&($s['argv']??null)===$argv)return $s;usleep(1000);}throw new RuntimeException('Cannot prove the exact native/SSH child lifetime.');
}
function unmLiveNativeSpawn(array $j,$input,$output,$backendRead,$backendWrite,bool $benchmark=false,$stderr=STDERR): array {
    if(!unmLiveAlive($j))throw new RuntimeException('Inactive native transport.');$key=unmLiveDataKeyCheck($j);
    $args=[$benchmark?'benchmark':'duplex',$j['id'],$j['role'],$key['keyId']];$binary='/usr/local/sbin/unmotion-live-stream';
    $p=proc_open(array_merge([$binary],$args),[0=>$input,1=>$output,2=>$stderr,3=>$backendRead,4=>$backendWrite,5=>['pipe','r'],6=>['pipe','w']],$pipes);
    if(!is_resource($p))throw new RuntimeException('Cannot launch native data transport.');
    try{$identity=unmLiveChildIdentity($p,array_merge([$binary],$args));}catch(Throwable $e){foreach($pipes as $pipe)fclose($pipe);proc_close($p);throw $e;}
    stream_set_blocking($pipes[6],false);return ['process'=>$p,'identity'=>$identity,'pipes'=>$pipes,'buffer'=>'','bytes'=>0,'exit'=>null];
}
function unmLiveNativeObserve(array &$child,?callable $progress=null): bool {
    $part=stream_get_contents($child['pipes'][6]);if($part===false)throw new RuntimeException('Native telemetry channel failed.');$child['buffer'].=$part;
    if(strlen($child['buffer'])>65536)throw new RuntimeException('Oversized native transport telemetry.');
    while(($at=strpos($child['buffer'],"\n"))!==false){$line=substr($child['buffer'],0,$at);$child['buffer']=substr($child['buffer'],$at+1);if(!preg_match('/\A(?:0|[1-9][0-9]{0,17})\z/D',$line)||strlen($line)>strlen((string)PHP_INT_MAX)||strlen($line)===strlen((string)PHP_INT_MAX)&&strcmp($line,(string)PHP_INT_MAX)>0||(int)$line<$child['bytes'])throw new RuntimeException('Invalid native byte counter.');$child['bytes']=(int)$line;if($progress)$progress($child['bytes']);}
    $status=proc_get_status($child['process']);if(!$status['running']){if($status['exitcode']>=0)$child['exit']=$status['exitcode'];return false;}return true;
}
function unmLiveNativeClose(array &$child): int {
    foreach($child['pipes'] as $p)if(is_resource($p))fclose($p); // withdraw lifeline before any signal
    if($child['identity'])unmProcessStop($child['identity'],1000);$closed=proc_close($child['process']);return $child['exit']!==null&&$child['exit']>=0?$child['exit']:$closed;
}
function unmLiveNativeRun(array $j,$backend=null,?callable $progress=null,bool $benchmark=false): void {
    $null=null;if($benchmark){$null=fopen('/dev/null','r+');$backend=$null;}
    $child=unmLiveNativeSpawn($j,STDIN,STDOUT,$backend,$backend,$benchmark);
    try{while(unmLiveNativeObserve($child,$progress)){if(!unmLiveAlive($j))throw new RuntimeException('Live data authority withdrawn.');usleep(10000);}unmLiveNativeObserve($child,$progress);}
    finally{$code=unmLiveNativeClose($child);if(is_resource($null))fclose($null);}
    if($code!==0)throw new RuntimeException('Native migration data connection failed.');
}
/** PHP admits/supervises handles. Only the native child reads VM payload. */
function unmLiveNativeRelay(string $id,string $channel): void {
    if(!in_array($channel,['memory','disk'],true))throw new RuntimeException('Invalid native data channel.');$j=unmLiveJob($id);if($j['role']!=='source'||!unmLiveAlive($j))throw new RuntimeException('Inactive source relay.');
    $runtime=unmLiveRuntime($id);unmLivePrivateDir($runtime);$path=$runtime.'/'.$channel.'.sock';if(file_exists($path)||is_link($path))throw new RuntimeException('Relay socket already exists.');
    $listener=stream_socket_server('unix://'.$path,$error,$text);if(!$listener)throw new RuntimeException('Cannot bind native data admission socket.');chmod($path,0600);stream_set_blocking($listener,false);
    $token=trim((string)file_get_contents($runtime.'/token'));$peer=unmLivePeerForJob($j);$connections=[];$log=fopen(dirname(unmLiveJobPath($id)).'/transport.log','a');
    try{while(unmLiveAlive($j)){
        $r=[$listener];$w=[];$e=[];if(@stream_select($r,$w,$e,0,100000)===false)throw new RuntimeException('Native admission socket failed.');
        if($r){$client=@stream_socket_accept($listener,0);if($client){if(count($connections)>=unmLiveDataLimit($j,$channel)){fclose($client);throw new RuntimeException('Too many migration data connections.');}
            $sshArgs=unmLiveSshArgs($peer,['jobId'=>$id,'token'=>$token,'channel'=>$channel],'live-data');$ssh=proc_open($sshArgs,[0=>['pipe','r'],1=>['pipe','w'],2=>$log],$pipes);if(!is_resource($ssh)){fclose($client);throw new RuntimeException('Cannot launch job-key SSH.');}
            try{$identity=unmLiveChildIdentity($ssh,$sshArgs);}catch(Throwable $ex){fclose($client);foreach($pipes as $p)fclose($p);proc_close($ssh);throw $ex;}
            try{$native=unmLiveNativeSpawn($j,$client,$client,$pipes[1],$pipes[0],false,$log);}catch(Throwable $ex){fclose($client);foreach($pipes as $p)fclose($p);if($identity)unmProcessStop($identity,1000);proc_close($ssh);throw $ex;}
            // No PHP reader/writer retains the payload endpoints after handoff.
            fclose($client);foreach($pipes as $p)fclose($p);$connections[]=['native'=>$native,'ssh'=>$ssh,'identity'=>$identity];
        }}
        foreach($connections as $k=>&$c)if(!unmLiveNativeObserve($c['native'])){unmLiveNativeObserve($c['native']);unmLiveNativeClose($c['native']);if($c['identity'])unmProcessStop($c['identity'],1000);proc_close($c['ssh']);unset($connections[$k]);}unset($c);
    }}finally{foreach($connections as &$c){unmLiveNativeClose($c['native']);if($c['identity'])unmProcessStop($c['identity'],1000);proc_close($c['ssh']);}unset($c);fclose($log);fclose($listener);if(file_exists($path)&&filetype($path)==='socket')unlink($path);}
}
