<?php
declare(strict_types=1);
// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
require __DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/cpu-compatibility.php';
$passed=0;
function cacheCheck(bool $ok,string $why):void {global $passed;if(!$ok)throw new RuntimeException($why);$passed++;}
function cacheReject(callable $call,string $why):void {try{$call();}catch(Throwable $e){cacheCheck(true,$why);return;}throw new RuntimeException('Accepted: '.$why);}
$root=sys_get_temp_dir().'/unmotion-cpu-cache-'.bin2hex(random_bytes(6));mkdir($root,0700);
$identity='cpu-one';$calls=0;$launches=0;
$report=['schemaVersion'=>2,'hostId'=>'host-one','hostname'=>'Squid Proxy','observedAt'=>'2026-11-08T12:00:00Z','displayOnly'=>true,'available'=>true,'complete'=>true,'context'=>['architecture'=>'x86_64','virtType'=>'kvm','vendor'=>'Intel','machine'=>'pc-q35-10.2'],'modes'=>[['id'=>'Skylake-Server-v3','status'=>'supported']],'error'=>''];
$inspect=function($request)use(&$calls,&$report){$calls++;return $report;};
$identityNow=fn()=>$GLOBALS['identity'];$launch=function($machine)use(&$launches){cacheCheck($machine==='q35','Only the requested local context is queued');$launches++;};
try{
    $readOnly=new UnmCpuCompatibilityCache($root.'/cold-read','cpu-one','host-one','host','boot',$root.'/cold-lock',true);
    cacheCheck(!$readOnly->view('q35')['available']&&!file_exists($root.'/cold-read')&&!file_exists($root.'/cold-lock'),'Cold paired reads create no cache/locks and launch no checks');
    cacheReject(fn()=>$readOnly->collect('q35',$inspect,$identityNow),'Read-only interface cannot calculate');
    $cache=new UnmCpuCompatibilityCache($root.'/persistent',$identity,'host-one','Squid Proxy','boot-one');
    $empty=$cache->view('q35');cacheCheck(!$empty['available']&&$empty['cache']['status']==='missing','Cold cached read is unavailable');
    cacheCheck($calls===0&&$launches===0,'Cached reads never calculate or launch');
    $cache->request('q35',$launch);$cache->request('q35',$launch);cacheCheck($launches===1,'Concurrent callers queue once');
    cacheCheck($cache->view('q35')['cache']['refreshing'],'Queued collection is visible');
    cacheCheck($cache->collect('q35',$inspect,$identityNow),'First collection publishes');
    $first=$cache->view('q35');cacheCheck($first['available']&&$first['cache']['cached'],'Cached support available');
    for($i=0;$i<20;$i++){$cache->request('q35',$launch);cacheCheck(!$cache->collect('q35',$inspect,$identityNow),'Ready result cannot be recomputed');}
    cacheCheck($calls===1&&$launches===1,'Repeated UI/worker reads reuse one detection');
    $reboot=new UnmCpuCompatibilityCache($root.'/persistent',$identity,'host-one','Squid Proxy','boot-two');
    cacheCheck($reboot->view('q35')['modes']===$report['modes'],'Boot changes preserve valid results');
    $rpc=new UnmCpuCompatibilityCache($root.'/persistent',$identity,'host-one','host','boot-two',$root.'/missing-ram-locks',true);
    cacheCheck($rpc->view('q35')['available']&&!file_exists($root.'/missing-ram-locks'),'Persisted results remain read-only and usable before RAM locks are recreated');
    $reboot->request('q35',$launch);cacheCheck($calls===1&&$launches===1,'Restart does not launch calculation');
    cacheCheck($reboot->view('pc-q35-10.2')['available'],'Resolved machine reuses alias observation');
    cacheCheck(!$reboot->collect('pc-q35-10.2',$inspect,$identityNow),'Alias cannot duplicate validation');
    cacheCheck(!$reboot->view('pc-q35-9.2')['available'],'Different machine is never silently substituted');
    $otherHost=new UnmCpuCompatibilityCache($root.'/persistent',$identity,'other-host','host-two','boot-two');
    cacheCheck(!$otherHost->view('q35')['available'],'Copied cache cannot impersonate another host');
    $identity='cpu-two';$changed=new UnmCpuCompatibilityCache($root.'/persistent',$identity,'host-one','Squid Proxy','boot-two');
    cacheCheck(!$changed->view('q35')['available']&&!$changed->view('q35')['modes'],'CPU change hides previous supported modes');
    $changed->request('q35',$launch);cacheCheck($launches===2,'CPU change queues a new detection');
    cacheCheck($changed->collect('q35',$inspect,$identityNow)&&$calls===2,'Changed CPU detected once');
    $badIdentity=function(){static $i=0;return ++$i===1?'cpu-three':'cpu-four';};
    $race=new UnmCpuCompatibilityCache($root.'/race','cpu-three','host-one','host','boot');
    cacheReject(fn()=>$race->collect('q35',$inspect,$badIdentity),'Mid-scan CPU change discards results');
    cacheCheck(!$race->view('q35')['available'],'Raced result not advertised');
    $unknown=$report;$unknown['complete']=false;$unknown['modes'][0]['status']='unknown';
    $partial=new UnmCpuCompatibilityCache($root.'/partial','cpu-two','host-one','host','boot');
    $partial->collect('q35',fn()=>$unknown,$identityNow);$partial->request('q35',$launch);
    cacheCheck($partial->view('q35')['modes'][0]['status']==='unknown'&&$launches===2,'Incomplete observations do not repeatedly validate or become supported');
    $unavailable=new UnmCpuCompatibilityCache($root.'/offline','cpu-two','host-one','host','boot');
    $failed=$report;$failed['available']=false;$failed['complete']=false;$failed['modes']=[];$failed['error']='VM Manager unavailable';
    $unavailable->collect('q35',fn()=>$failed,$identityNow);$unavailable->request('q35',$launch);
    cacheCheck($launches===2&&!$unavailable->view('q35')['available'],'Offline collection is bounded by retry backoff');
    $unsafe=$root.'/link';symlink($root.'/persistent',$unsafe);cacheReject(fn()=>new UnmCpuCompatibilityCache($unsafe,'cpu-two','host-one','host','boot'),'Redirected directory refused');
    $bad=$root.'/persistent/context-'.hash('sha256','pc').'.json';symlink($root.'/persistent/context-'.hash('sha256','q35').'.json',$bad);
    cacheReject(fn()=>$changed->view('pc'),'Redirected report refused');unlink($bad);
    foreach(glob($root.'/persistent/context-*.json')?:[] as $file)cacheCheck((fileperms($file)&0077)===0,'POSIX report is private');
    $cpu="processor : 0\nvendor_id : GenuineIntel\nmodel : 85\nstepping : 4\nmicrocode : 0x1\nflags : avx sse tsc\ncpu MHz : 2200\n";
    $fingerprint=unmCpuCompatibilityCpuFingerprint($cpu,'x86_64');
    cacheCheck($fingerprint===unmCpuCompatibilityCpuFingerprint(str_replace(['2200','avx sse tsc'],['800','tsc sse avx'],$cpu),'x86_64'),'Clock speed and flag order are not CPU changes');
    foreach(['0x1'=>'0x2','GenuineIntel'=>'AuthenticAMD','85'=>'86','avx sse tsc'=>'sse tsc'] as $from=>$to)cacheCheck($fingerprint!==unmCpuCompatibilityCpuFingerprint(str_replace((string)$from,(string)$to,$cpu),'x86_64'),'Effective CPU change detected');
    cacheCheck($fingerprint===unmCpuCompatibilityCpuFingerprint($cpu."\n".str_replace('processor : 0','processor : 1',$cpu),'x86_64'),'Logical CPU duplicates do not spuriously invalidate');
    cacheReject(fn()=>unmCpuCompatibilityCpuFingerprint('','x86_64'),'Missing CPU identity fails closed');
    $clock=new UnmCpuCompatibilityCache($root.'/clock','cpu-two','host-one','host','boot');
    $clockFile=$root.'/clock/context-'.hash('sha256','q35').'.json';
    file_put_contents($clockFile,json_encode(['schema'=>2,'hostId'=>'host-one','fingerprint'=>'cpu-two','bootId'=>'boot','requestedAt'=>time()+3600,'attemptAt'=>time()+3600,'report'=>'corrupt']));
    cacheCheck(!$clock->view('q35')['available']&&!$clock->view('q35')['cache']['refreshing'],'Corrupt report/future queue timestamp cannot imply support or permanent activity');
    $queued=0;$clock->request('q35',function()use(&$queued){$queued++;});cacheCheck($queued===1,'Clock rollback does not permanently block retry');
    $lockPath=$root.'/persistent/locks/collector.lock';$held=fopen($lockPath,'c');flock($held,LOCK_EX);
    $never=false;$ran=$changed->collect('pc',function()use(&$never){$never=true;throw new RuntimeException('Concurrent collector ran');},$identityNow);
    cacheCheck(!$ran&&!$never,'A second collector cannot inspect while the global lock is held');fclose($held);
    $agent=file_get_contents(__DIR__.'/../src/rootfs/usr/local/sbin/unmotion-agent');
    $action=explode("case 'migration-nvram-check':",explode("case 'cpu-compatibility':",$agent,2)[1],2)[0];
    cacheCheck(str_contains($action,'unmCpuCompatibilityCached($request)')&&!str_contains($action,'unmCpuCompatibilityLocal'),'Paired endpoint only reads cached results');
    $rc=file_get_contents(__DIR__.'/../src/rootfs/etc/rc.d/rc.unmotion');cacheCheck(str_contains($rc,'unmotion-cpu-compatibility warmup'),'Startup queues warmup');
    echo "CPU persistent cache and invalidation: $passed assertions passed\n";
}finally{
    $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($iterator as $file){if($file->isDir()&&!$file->isLink())rmdir($file->getPathname());else unlink($file->getPathname());}rmdir($root);
}
