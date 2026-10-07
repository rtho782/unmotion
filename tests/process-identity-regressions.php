<?php
declare(strict_types=1);
require_once __DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/process-identity.php';

if(($argv[1]??'')==='--fixture'){
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM,($argv[3]??'')==='stubborn'?SIG_IGN:static function():void{exit(0);});
    $child=proc_open(['/bin/sleep','60'],[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes);
    echo json_encode(['pid'=>getmypid(),'child'=>proc_get_status($child)['pid']]),"\n";flush();
    while(true)usleep(100000);
}

function check(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
function startFixture(string $job,string $mode='cooperative'):array{
    $process=proc_open([PHP_BINARY,__FILE__,'--fixture',$job,$mode],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['file','/dev/null','w']],$pipes);
    check(is_resource($process),'Fixture must launch');
    stream_set_timeout($pipes[1],3);$row=json_decode((string)fgets($pipes[1]),true);fclose($pipes[1]);
    check(is_array($row),'Fixture must report readiness');
    $identity=unmProcessSnapshot($row['pid']);check($identity!==null,'Fixture process must be inspectable');
    return [$process,$identity,$row['child']];
}
function removeTestTree(string $root):void{
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $item){
        $item->isDir()&&!$item->isLink()?rmdir($item->getPathname()):unlink($item->getPathname());
    }
    rmdir($root);
}

check(PHP_OS_FAMILY==='Linux','Process identity regression tests require Linux /proc');
check(function_exists('posix_kill')&&function_exists('pcntl_signal'),'Process identity tests require PHP POSIX and PCNTL');
$temporary=sys_get_temp_dir().'/unmotion-process-test-'.bin2hex(random_bytes(8));mkdir($temporary,0700);
$fixtures=[];
try{
    [$process,$identity,$child]=startFixture('Squid Proxy');$fixtures[]=[$process,$identity];
    [$sibling,$other,$otherChild]=startFixture('unrelated-job');$fixtures[]=[$sibling,$other];
    check(unmProcessIdentityMatches($identity),'Exact lifetime identity accepted');
    check(unmProcessCommandMatches($identity,__FILE__,['--fixture','Squid Proxy']),'Exact script and job identity accepted');
    check(!unmProcessCommandMatches($identity,__FILE__,['--fixture','other-job']),'Wrong job rejected');
    check(!unmProcessCommandMatches(['executable'=>'/bin/echo','argv'=>['echo','--fake',__FILE__,'Squid Proxy']],__FILE__,['Squid Proxy']),'Script mentioned in arbitrary argv is not ownership');
    check(!unmProcessCommandMatches(['executable'=>'/usr/bin/php','argv'=>['php','-r',__FILE__,'Squid Proxy']],__FILE__,['Squid Proxy']),'PHP -r argv bait is not a script worker');
    check(!unmProcessCommandMatches(['executable'=>'/bin/sleep','argv'=>['bash',__FILE__,'Squid Proxy']],__FILE__,['Squid Proxy']),'Spoofed argv interpreter rejected');
    $path=$temporary.'/identity.json';
    $saved=unmProcessRegister($path,$identity['pid'],__FILE__,['--fixture','Squid Proxy']);
    check(unmProcessReadIdentity($path,$identity['pid'],__FILE__,['--fixture','Squid Proxy'])!==null,'Saved identity round trip');
    check(unmProcessReadIdentity($path,$other['pid'],__FILE__,['--fixture','Squid Proxy'])===null,'Saved PID mismatch rejected');
    check(unmProcessReadIdentity($path,$identity['pid'],__FILE__,['--fixture','unrelated-job'])===null,'Saved job mismatch rejected');
    foreach(['bootId'=>'00000000-0000-0000-0000-000000000000','startTicks'=>'1','processGroup'=>1,'session'=>1,'executable'=>'/bin/false','executableInode'=>'0','argv'=>['unrelated']] as $key=>$value){
        $stale=$saved;$stale[$key]=$value;
        check(!unmProcessIdentityMatches($stale),'Stale '.$key.' rejected');
        check(!unmProcessSignal($stale,15),'Stale '.$key.' cannot TERM');
        check(!unmProcessSignal($stale,9),'Stale '.$key.' cannot KILL');
        check(unmProcessIdentityMatches($identity),'Original worker survives stale '.$key.' record');
    }
    check(unmProcessCleanupCandidate($temporary.'/missing',$other['pid'],__FILE__,['--fixture','Squid Proxy'])===null,'Unrelated saved PID ignored during cleanup');
    $refused=false;try{unmProcessCleanupCandidate($temporary.'/missing',$identity['pid'],__FILE__,['--fixture','Squid Proxy']);}catch(RuntimeException $error){$refused=true;}
    check($refused,'Exact legacy worker with no lifetime record blocks unsafe uninstall');
    check(unmProcessLegacyServiceAllowed('/usr/local/sbin/unmotion-replication-scheduler',[]),'Legacy scheduler may be adopted');
    check(unmProcessLegacyServiceAllowed('/usr/local/sbin/unmotion-replication-lifecycle',['--daemon']),'Legacy lifecycle daemon may be adopted');
    check(!unmProcessLegacyServiceAllowed('/usr/local/sbin/unmotion-replication-lifecycle',['--once']),'Other lifecycle mode cannot be adopted');
    check(!unmProcessLegacyServiceAllowed('/usr/local/sbin/unmotion-worker',['job-id']),'Legacy migration PID cannot be adopted');
    check(unmProcessAdoptLegacyService($temporary.'/legacy',$identity['pid'],__FILE__,['--fixture','Squid Proxy'])===null,'Arbitrary fixture cannot take service adoption path');
    $tree=unmProcessTree($saved);$pids=array_column($tree,'pid');
    check(in_array($identity['pid'],$pids,true)&&in_array($child,$pids,true),'Verified worker and descendant included');
    check(!in_array($other['pid'],$pids,true)&&!in_array($otherChild,$pids,true)&&!in_array(getmypid(),$pids,true),'Sibling and parent in the same process group excluded');
    check(unmProcessStop($saved,1000),'Cooperative worker and child stopped');
    check(unmProcessIdentityMatches($other),'Unrelated sibling survives TERM');
    check(!unmProcessIdentityMatches($saved)&&!unmProcessSignal($saved,9),'Exited identity cannot authorize escalation');

    [$stubbornProcess,$stubborn,$stubbornChild]=startFixture('stubborn','stubborn');$fixtures[]=[$stubbornProcess,$stubborn];
    check(!unmProcessStop($stubborn,100),'Service grace timeout reports still active, no blind escalation');
    check(unmProcessIdentityMatches($stubborn),'TERM-ignoring worker retained without escalation');
    check(unmProcessStop($stubborn,100,true),'Explicit escalation stops only captured exact lifetime');
    check(unmProcessIdentityMatches($other),'Unrelated sibling survives KILL escalation');

    // A synthetic /proc tree checks parsing of comm with spaces and ')' and
    // PID reuse across boots/start ticks without creating arbitrary real PIDs.
    $proc=$temporary.'/proc';mkdir($proc.'/sys/kernel/random',0700,true);mkdir($proc.'/4321');
    file_put_contents($proc.'/sys/kernel/random/boot_id','12345678-1234-1234-1234-123456789abc');
    $fields=array_fill(0,21,'0');$fields[0]='S';$fields[1]='123';$fields[2]='4321';$fields[3]='4321';$fields[19]='987654321';
    file_put_contents($proc.'/4321/stat','4321 (worker ) name) '.implode(' ',$fields));
    file_put_contents($proc.'/4321/cmdline',PHP_BINARY."\0".__FILE__."\0--fixture\0Squid Proxy\0");
    symlink(realpath(PHP_BINARY),$proc.'/4321/exe');
    $synthetic=unmProcessSnapshot(4321,$proc);check($synthetic!==null&&$synthetic['startTicks']==='987654321','stat comm parsing preserves correct start field');
    check(unmProcessIdentityMatches($synthetic,$proc),'Synthetic identity accepted');
    $fields[19]='987654322';file_put_contents($proc.'/4321/stat','4321 (worker ) name) '.implode(' ',$fields));
    check(!unmProcessIdentityMatches($synthetic,$proc),'Reused PID with identical command rejected by start tick');
    $fields[19]='987654321';$fields[0]='Z';file_put_contents($proc.'/4321/stat','4321 (worker ) name) '.implode(' ',$fields));
    check(unmProcessSnapshot(4321,$proc)===null,'Zombie is not a running worker');

    $lib=(string)file_get_contents(__DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/lib.php');
    check(!str_contains($lib,"kill -TERM -- -")&&!str_contains($lib,"kill -KILL -- -")&&!str_contains($lib,"pkill -TERM -P"),'Cancellation/cleanup contain no blind PID or group kills');
    echo "Process identity regressions passed.\n";
}finally{
    foreach($fixtures as [$fixtureProcess,$fixtureIdentity]){unmProcessStop($fixtureIdentity,100,true);proc_close($fixtureProcess);}
    removeTestTree($temporary);
}
