<?php
declare(strict_types=1);
require_once __DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/process-identity.php';
require_once __DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/process-upgrade.php';
require_once __DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/lib.php';
$temporary=(string)($argv[1]??'');
if(!str_starts_with($temporary,'/tmp/unmotion-process-service.')||!is_dir($temporary))throw new RuntimeException('Isolated test root missing.');
$fixtures=[];
function serviceCheck(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
function serviceFixture(string $script,array $args=[]):array{
    serviceCheck(str_contains((string)file_get_contents($script),'UNMOTION_ISOLATED_PROCESS_SERVICE_FIXTURE'),'Refusing to launch a real service');
    $process=proc_open(array_merge([PHP_BINARY,$script],$args),[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['file','/dev/null','w']],$pipes);
    stream_set_timeout($pipes[1],3);$pid=(int)fgets($pipes[1]);fclose($pipes[1]);
    $identity=unmProcessSnapshot($pid);serviceCheck($identity!==null,'Service fixture ready');
    return [$process,$identity];
}
function serviceStopCommand(string $path,int $pid,string $script,array $args=[]):int{
    $process=proc_open(array_merge(['/usr/local/sbin/unmotion-process','stop-service',$path,(string)$pid,$script],$args),[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes);
    return proc_close($process);
}
try{
    $scheduler='/usr/local/sbin/unmotion-replication-scheduler';$lifecycle='/usr/local/sbin/unmotion-replication-lifecycle';
    [$first,$identity]=serviceFixture($scheduler);$fixtures[]=[$first,$identity];
    serviceCheck(serviceStopCommand($temporary.'/legacy-scheduler',$identity['pid'],$scheduler)===0,'Exact legacy scheduler is safely adopted and stopped');
    serviceCheck(!unmProcessIdentityMatches($identity),'Adopted scheduler exited');
    $adopted=json_decode((string)file_get_contents($temporary.'/legacy-scheduler'),true);
    serviceCheck($adopted['startTicks']===$identity['startTicks']&&$adopted['bootId']===$identity['bootId'],'Adoption persists original lifetime');

    [$daemon,$daemonIdentity]=serviceFixture($lifecycle,['--daemon']);$fixtures[]=[$daemon,$daemonIdentity];
    serviceCheck(serviceStopCommand($temporary.'/legacy-lifecycle',$daemonIdentity['pid'],$lifecycle,['--daemon'])===0,'Exact legacy lifecycle daemon safely adopted and stopped');
    serviceCheck(!unmProcessIdentityMatches($daemonIdentity),'Adopted lifecycle exited');

    [$stale,$staleIdentity]=serviceFixture($scheduler);$fixtures[]=[$stale,$staleIdentity];
    $tampered=$staleIdentity;$tampered['startTicks']='0';file_put_contents($temporary.'/stale',json_encode($tampered));
    serviceCheck(serviceStopCommand($temporary.'/stale',$staleIdentity['pid'],$scheduler)===75,'Existing stale record cannot be overwritten by adoption');
    serviceCheck(unmProcessIdentityMatches($staleIdentity),'Stale-record process preserved');

    [$extra,$extraIdentity]=serviceFixture($scheduler,['--unexpected']);$fixtures[]=[$extra,$extraIdentity];
    serviceCheck(serviceStopCommand($temporary.'/extra',$extraIdentity['pid'],$scheduler)===75,'Prefix alone does not authorize legacy adoption');
    serviceCheck(unmProcessIdentityMatches($extraIdentity)&&!file_exists($temporary.'/extra'),'Unexpected argv remains alive without adopted identity');

    serviceCheck(serviceStopCommand($temporary.'/unrelated',$extraIdentity['pid'],$lifecycle,['--daemon'])===0,'Reused unrelated service PID is ignored');
    serviceCheck(unmProcessIdentityMatches($extraIdentity),'Unrelated process not signalled');

    // Exercise the real API cancellation and uninstall code against /boot and
    // /var/lib overlays, with stale records deliberately pointing at a live
    // unrelated process. No VMs, ZFS objects or real plugin data are present.
    unmEnsureDirs();$jobId='job-stale-pid';$jobDir=UNM_JOBS_DIR.'/'.$jobId;mkdir($jobDir,0700);
    unmAtomicJson($jobDir.'/job.json',['id'=>$jobId,'pid'=>$extraIdentity['pid'],'state'=>'TRANSFERRING','jobType'=>'migration']);
    $cancelled=unmCancelJob($jobId);
    serviceCheck(($cancelled['state']??'')==='CANCELLED','Stale-PID job cancellation records cancellation');
    serviceCheck(unmProcessIdentityMatches($extraIdentity),'Cancellation cannot signal unrelated saved PID');

    $activeId='job-owned-pid';$activeDir=UNM_JOBS_DIR.'/'.$activeId;mkdir($activeDir,0700);
    [$active,$activeIdentity]=serviceFixture('/usr/local/sbin/unmotion-worker',[$activeId]);$fixtures[]=[$active,$activeIdentity];
    unmAtomicJson($activeDir.'/job.json',['id'=>$activeId,'pid'=>$activeIdentity['pid'],'state'=>'TRANSFERRING','jobType'=>'migration']);
    unmProcessRegister($activeDir.'/process.json',$activeIdentity['pid'],'/usr/local/sbin/unmotion-worker',[$activeId]);
    unmCancelJob($activeId);
    for($attempt=0;$attempt<20&&unmProcessIdentityMatches($activeIdentity);$attempt++)usleep(50000);
    serviceCheck(!unmProcessIdentityMatches($activeIdentity),'Cancellation stops a verified exact worker');

    $seedDir=UNM_SEEDS_DIR.'/seed-stale-pid';mkdir($seedDir,0700);
    unmAtomicJson($seedDir.'/seed.json',['id'=>'seed-stale-pid','pid'=>$extraIdentity['pid'],'state'=>'FAILED','storage'=>[]]);
    $incoming=UNM_BOOT_DIR.'/incoming/job-stale-pid';mkdir($incoming,0700,true);
    unmAtomicJson($incoming.'/source-cleanup-request.json',['pid'=>$extraIdentity['pid'],'state'=>'complete']);
    $outside=$temporary.'/outside-plugin-state';file_put_contents($outside,'preserve');
    unmCleanupAll();
    serviceCheck(unmProcessIdentityMatches($extraIdentity),'Uninstall ignores stale job, seed and delayed-cleanup PIDs');
    serviceCheck(!is_dir($jobDir)&&!is_dir($seedDir)&&is_file($outside),'Only isolated exact plugin state was removed');
    foreach($fixtures as [$fixtureProcess,$fixtureIdentity])unmProcessStop($fixtureIdentity,100,true);

    [$upgradeDaemon,$upgradeDaemonIdentity]=serviceFixture($scheduler);$fixtures[]=[$upgradeDaemon,$upgradeDaemonIdentity];
    [$upgradeJob,$upgradeJobIdentity]=serviceFixture('/usr/local/sbin/unmotion-worker',['job-upgrade-active']);$fixtures[]=[$upgradeJob,$upgradeJobIdentity];
    $refused=false;try{unmProcessPreinstall();}catch(RuntimeException $error){$refused=str_contains($error->getMessage(),'worker is active');}
    serviceCheck($refused&&unmProcessIdentityMatches($upgradeDaemonIdentity)&&unmProcessIdentityMatches($upgradeJobIdentity),'Upgrade with active worker refused before stopping daemon or worker');
    unmProcessStop($upgradeJobIdentity,100,true);
    foreach([
        'unmotion-ssh-gate'=>[str_repeat('a',64)],
        'unmotion-agent'=>['replication-publish','e30='],
        'unmotion-start-destination'=>['12345678-1234-1234-1234-123456789abc','524288'],
    ] as $remoteScript=>$remoteArgs){
        [$remoteProcess,$remoteIdentity]=serviceFixture('/usr/local/sbin/'.$remoteScript,$remoteArgs);$fixtures[]=[$remoteProcess,$remoteIdentity];
        $refused=false;try{unmProcessPreinstall();}catch(RuntimeException $error){$refused=str_contains($error->getMessage(),'worker is active');}
        serviceCheck($refused&&unmProcessIdentityMatches($remoteIdentity)&&unmProcessIdentityMatches($upgradeDaemonIdentity),'Incoming '.$remoteScript.' blocks upgrade without stopping request or scheduler');
        unmProcessStop($remoteIdentity,100,true);
    }
    unmProcessPreinstall();
    serviceCheck(!unmProcessIdentityMatches($upgradeDaemonIdentity),'Idle upgrade guard stops only exact daemon');
    serviceCheck(is_file($outside),'Upgrade guard preserves unrelated and persistent files');
    echo "Isolated legacy service process regressions passed.\n";
}finally{
    foreach($fixtures as [$process,$identity]){unmProcessStop($identity,100,true);proc_close($process);}
}
