<?php
// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
declare(strict_types=1);

/** Live control/query commands only; no shell or fixed completion-poll delay.
 * A timeout is a command failure, never evidence that a remote operation stopped.
 * The long-running native migration itself is NOT executed through this runner.
 */
function unmLiveRunCommand(array $args,?string $input=null,int $seconds=20): array {
    if(PHP_OS_FAMILY!=='Linux')throw new RuntimeException('Live command transport requires Linux stream descriptors.');
    if(!$args)throw new InvalidArgumentException('An executable is required.');
    foreach($args as $arg)if(!is_scalar($arg)||str_contains((string)$arg,"\0"))throw new InvalidArgumentException('Invalid command argument.');
    $p=proc_open(array_map('strval',$args),[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($p))return ['code'=>127,'stdout'=>'','stderr'=>'Unable to start live command.'];
    foreach($pipes as $pipe)stream_set_blocking($pipe,false);
    $data=$input??'';$sent=0;$out='';$err='';$exit=null;$timedOut=false;$ioError=false;$deadline=microtime(true)+max(1,$seconds);
    try{
        while(true){
            if(isset($pipes[0])&&$sent===strlen($data)){fclose($pipes[0]);unset($pipes[0]);}
            $status=proc_get_status($p);
            if(!$status['running']){if($status['exitcode']>=0)$exit=$status['exitcode'];break;}
            $left=$deadline-microtime(true);if($left<=0){$timedOut=true;break;}
            $r=[];foreach([1,2] as $i)if(isset($pipes[$i]))$r[]=$pipes[$i];$w=isset($pipes[0])?[$pipes[0]]:[];$e=[];
            if(!$r&&!$w){usleep((int)(min(0.001,$left)*1000000));continue;}
            $wait=(int)max(1,min(200000,$left*1000000));if(@stream_select($r,$w,$e,0,$wait)===false){$ioError=true;break;}
            foreach($r as $pipe){$text=stream_get_contents($pipe,262144);if($text===false){$ioError=true;break 2;}if($pipe===($pipes[1]??null))$out.=$text;else$err.=$text;if(feof($pipe))foreach([1,2] as $i)if(($pipes[$i]??null)===$pipe){fclose($pipe);unset($pipes[$i]);break;}}
            if($w){$n=@fwrite($pipes[0],substr($data,$sent,262144));if($n===false){fclose($pipes[0]);unset($pipes[0]);}else $sent+=$n;}
        }
        if($timedOut||$ioError){
            // This is the still-owned proc_open child, not a saved numeric PID.
            if(isset($pipes[0])){fclose($pipes[0]);unset($pipes[0]);}
            proc_terminate($p,15);$until=microtime(true)+0.25;
            do{$status=proc_get_status($p);if(!$status['running'])break;usleep(10000);}while(microtime(true)<$until);
            if($status['running'])proc_terminate($p,9);
        }
        foreach([1,2] as $i)if(isset($pipes[$i])){$text=stream_get_contents($pipes[$i]);if($text!==false){if($i===1)$out.=$text;else$err.=$text;}}
    }finally{foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);$closed=proc_close($p);}
    if($timedOut)return ['code'=>124,'stdout'=>$out,'stderr'=>$err."\nTimed out after {$seconds}s"];
    if($ioError||$sent!==strlen($data))return ['code'=>74,'stdout'=>$out,'stderr'=>$err."\nLive command stream failed or input was not fully delivered."];
    return ['code'=>$exit!==null?$exit:$closed,'stdout'=>$out,'stderr'=>$err];
}

/** Atomic records are already written; flush every containing filesystem.
 * Never treat a runtime timing/progress file as a substitute for this barrier.
 */
function unmLiveSyncRecords(array $paths,?callable $command=null): void {
    $command??='unmLiveCommand';$filesystems=[];
    foreach($paths as $path){clearstatcache(true,$path);$s=@lstat($path);if(!$s||is_link($path)||!is_file($path))throw new RuntimeException('Durable live record is missing or redirected.');$filesystems[(string)$s['dev']]??=$path;}
    foreach($filesystems as $path)$command(['sync','-f',$path]);
}
