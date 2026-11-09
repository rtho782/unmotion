<?php
declare(strict_types=1);
if (($argv[1] ?? '') === '--child') {
    echo "ready\n"; flush(); usleep(500000); exit;
}
require __DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/lib.php';
$checks=0;
function check(bool $v,string $message): void {global $checks;if(!$v)throw new RuntimeException($message);$checks++;}
$id='live-'.str_repeat('a',24);
$worker=['executable'=>'/usr/bin/php','argv'=>['php','/usr/local/sbin/unmotion-live-worker',$id]];
unmLiveAssertWorkerGone($id,[]);check(true,'absent worker permits inspection');
$denied=false;try{unmLiveAssertWorkerGone($id,[$worker]);}catch(RuntimeException){$denied=true;}
check($denied,'an exact worker blocks reconciliation even without a saved process record');
unmLiveAssertWorkerGone('live-'.str_repeat('b',24),[$worker]);check(true,'another job is not this worker');
unmLiveAssertWorkerGone($id,[['executable'=>'/usr/bin/php','argv'=>['php','-r','/usr/local/sbin/unmotion-live-worker',$id]]]);check(true,'argv bait is not a worker');
$engine=(string)file_get_contents(__DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/live-engine.php');
$commit=substr($engine,strpos($engine,"if(\$action==='commit')"));
check(strpos($commit,"\$j['state']='COMMITTING'")<strpos($commit,"unmLiveAuthority(\$j,'OWNER'"),'commit intent precedes its durable execution authority grant');
check(strpos($commit,"sync','-f',unmLiveJobPath")<strpos($commit,"unmLiveAuthority(\$j,'OWNER'"),'commit intent is synced before granting ownership');
if(PHP_OS_FAMILY==='Linux') {
    $uuid='b3b3b3b3-1111-4111-8111-'.bin2hex(random_bytes(6));
    $path='/var/lock/unmotion-'.$uuid.'.lock';$lock=unmLiveVmLock($uuid);$process=null;
    try {
        $process=proc_open([PHP_BINARY,__FILE__,'--child'],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['file','/dev/null','w']],$pipes);
        check(is_resource($process),'child launched');stream_set_timeout($pipes[1],3);
        check(trim((string)fgets($pipes[1]))==='ready','child has execed');fclose($pipes[1]);
        // Close, rather than explicitly unlock: a leaked duplicate in the child
        // would still hold the original open-file-description lock here.
        fclose($lock);$lock=null;$next=fopen($path,'ce');
        check(flock($next,LOCK_EX|LOCK_NB),'transport exec cannot inherit the VM lock');fclose($next);
    } finally {
        if(is_resource($lock))fclose($lock);if(is_resource($process))proc_close($process);if(is_file($path))unlink($path);
    }
}
echo "Live worker regressions passed: $checks checks.\n";
