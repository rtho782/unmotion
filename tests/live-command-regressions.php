<?php
declare(strict_types=1);
if(($argv[1]??'')==='--child'){
    switch($argv[2]??''){
        case 'args':echo json_encode(array_slice($argv,3));exit;
        case 'duplex':$n=0;while(!feof(STDIN)){$s=fread(STDIN,32768);$n+=strlen($s);fwrite(STDOUT,$s);fwrite(STDERR,str_repeat('E',strlen($s)));}exit($n===2097152?0:21);
        case 'failure':fwrite(STDOUT,"out\0binary");fwrite(STDERR,'expected error');exit(17);
        case 'sleep':echo getmypid()."\n";flush();sleep(30);exit;
        case 'eof':echo stream_get_contents(STDIN)===''?'empty':'not-empty';exit;
    }exit(2);
}
require __DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/live-command.php';
if(PHP_OS_FAMILY!=='Linux'){echo "SKIP: live command descriptor regressions require the Linux/Unraid test host.\n";exit;}
$checks=0;function commandCheck(bool $v,string $message):void{global $checks;if(!$v)throw new RuntimeException($message);$checks++;}
$literal=['two words','$(touch NEVER)','; echo injection','quote\'"','back\\slash'];
$r=unmLiveRunCommand(array_merge([PHP_BINARY,__FILE__,'--child','args'],$literal));commandCheck($r['code']===0&&json_decode($r['stdout'],true)===$literal&&$r['stderr']==='','Arguments remain literal without a shell');
$input=str_repeat("AB\0C",524288);$r=unmLiveRunCommand([PHP_BINARY,__FILE__,'--child','duplex'],$input,15);commandCheck($r['code']===0&&$r['stdout']===$input&&$r['stderr']===str_repeat('E',strlen($input)),'Large stdin/stdout/stderr are drained concurrently and remain binary-isolated');
$r=unmLiveRunCommand([PHP_BINARY,__FILE__,'--child','failure']);commandCheck($r===['code'=>17,'stdout'=>"out\0binary",'stderr'=>'expected error'],'Exit status and separate binary stdout/stderr survive early exit');
$r=unmLiveRunCommand([PHP_BINARY,__FILE__,'--child','eof']);commandCheck($r['code']===0&&$r['stdout']==='empty','Empty stdin is closed promptly');
$t=microtime(true);$r=unmLiveRunCommand([PHP_BINARY,__FILE__,'--child','sleep'],null,1);commandCheck($r['code']===124&&microtime(true)-$t<4&&str_contains($r['stderr'],'Timed out'),'Command timeout fails and reaps its owned child');
if(PHP_OS_FAMILY==='Linux')commandCheck(!is_dir('/proc/'.trim($r['stdout'])),'Timed-out exact child is absent');
foreach([[],[PHP_BINARY,"bad\0argument"]] as $bad){$denied=false;try{unmLiveRunCommand($bad);}catch(InvalidArgumentException){$denied=true;}commandCheck($denied,'Invalid executable/argument shape rejected');}
$dir=sys_get_temp_dir().'/unmotion-live-command-'.bin2hex(random_bytes(6));mkdir($dir,0700);$calls=[];
try{foreach(['a','b'] as $name)file_put_contents($dir.'/'.$name,'record');unmLiveSyncRecords([$dir.'/a',$dir.'/b'],static function($args)use(&$calls){$calls[]=$args;});commandCheck($calls===[['sync','-f',$dir.'/a']],'Two records on one filesystem require one barrier');
    $denied=false;try{unmLiveSyncRecords([$dir.'/a',$dir.'/missing'],static function(){});}catch(RuntimeException){$denied=true;}commandCheck($denied,'Missing record cannot be acknowledged as durable');
    if(PHP_OS_FAMILY==='Linux'){symlink($dir.'/a',$dir.'/alias');$denied=false;try{unmLiveSyncRecords([$dir.'/alias'],static function(){});}catch(RuntimeException){$denied=true;}commandCheck($denied,'Redirected record cannot be acknowledged as durable');unlink($dir.'/alias');
        if(is_writable('/dev/shm')&&stat('/dev/shm')['dev']!==stat($dir)['dev']){$other='/dev/shm/unmotion-live-command-'.bin2hex(random_bytes(6));file_put_contents($other,'record');try{$calls=[];unmLiveSyncRecords([$dir.'/a',$other],static function($a)use(&$calls){$calls[]=$a;});commandCheck(count($calls)===2,'Separate record filesystems each receive a barrier');}finally{unlink($other);}}
    }
}finally{foreach(['a','b'] as $name)unlink($dir.'/'.$name);rmdir($dir);}
$samples=[];for($i=0;$i<10;$i++){$t=hrtime(true);$r=unmLiveRunCommand([PHP_BINARY,__FILE__,'--child','eof']);commandCheck($r['code']===0,'Repeated fast command completes');$samples[]=(hrtime(true)-$t)/1e6;}
echo 'Live event-driven command regressions passed: '.$checks.'; average fast command '.round(array_sum($samples)/count($samples),2)." ms.\n";
