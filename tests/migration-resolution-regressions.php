<?php
declare(strict_types=1);
// Post-recovery beta4 transaction regressions. All libvirt and peer operations
// are in-memory stubs; only the isolated test journal is written to disk.
$root=sys_get_temp_dir().'/unmotion-resolution-'.bin2hex(random_bytes(6));
mkdir($root,0700);define('UNM_JOBS_DIR',$root.'/jobs');define('UNM_BOOT_DIR',$root);
define('UNM_OWNERSHIP_DIR',$root.'/ownership');mkdir(UNM_JOBS_DIR);mkdir(UNM_OWNERSHIP_DIR);
require __DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/migration-resolution.php';
function resolutionCheck(bool $yes,string $why):void{if(!$yes)throw new RuntimeException($why);}
function resolutionReject(callable $fn,string $why):void{try{$fn();}catch(RuntimeException $e){return;}throw new RuntimeException('Accepted unsafe case: '.$why);}
function unmLoadJson(string $path,array $default=[]):array{return is_file($path)?(json_decode(file_get_contents($path),true)?:$default):$default;}
function unmAtomicJson(string $path,array $data):void{file_put_contents($path,json_encode($data));}
function unmHostId():string{return 'source-host';}
function unmPeer(string $id):array{return ['hostId'=>$GLOBALS['peerHost'],'host'=>'DEV02'];}
function unmPeers():array{return [['hostId'=>$GLOBALS['peerHost']]];}
function unmScheduleSourceCleanup(array $request):array{$GLOBALS['scheduled'][]=$request;return ['success'=>true];}
function unmRecoveryAssertLegacyVmAvailable(string $uuid,string $operation):void{}
function unmRecoveryNativeAutostart(string $uuid):bool{return $GLOBALS['auto'];}
function testXml(string $uuid,string $name,string $disk='/mnt/pool/Squid Proxy/disk.img'):string{
    return '<domain><name>'.htmlspecialchars($name,ENT_XML1).'</name><uuid>'.$uuid.'</uuid><devices><disk type="file" device="disk"><source file="'.htmlspecialchars($disk,ENT_XML1).'"/><target dev="vda"/></disk><hostdev type="usb"><source><address bus="1" device="2"/></source></hostdev></devices></domain>';
}
function unmRun($cmd,$stdin=null,$timeout=15):array{
    $GLOBALS['commands'][]=$cmd;$out='';$code=0;
    switch($cmd[1]??''){
        case 'domstate':if($GLOBALS['state']==='undefined'||$GLOBALS['inventoryFailure'])$code=1;else $out=$GLOBALS['state'];break;
        case 'list':if($GLOBALS['inventoryFailure'])$code=1;else $out=$GLOBALS['state']==='undefined'?'':$GLOBALS['uuid'];break;
        case 'dumpxml':if($GLOBALS['state']==='undefined')$code=1;else $out=testXml($GLOBALS['uuid'],$GLOBALS['name'],$GLOBALS['disk']);break;
        case 'autostart':$GLOBALS['auto']=false;break;
        case 'domname':$out=$GLOBALS['name'];break;
        case 'domuuid':$code=1;break;
        case 'domrename':$GLOBALS['name']=$cmd[3];$GLOBALS['renames']++;if($GLOBALS['failRename']){$GLOBALS['failRename']=false;$code=1;}break;
        case 'undefine':$GLOBALS['state']='undefined';$GLOBALS['undefines']++;if($GLOBALS['failUndefine']){$GLOBALS['failUndefine']=false;$code=1;}break;
        default:throw new RuntimeException('Unexpected mocked command '.json_encode($cmd));
    }
    return ['code'=>$code,'stdout'=>$out,'stderr'=>$code?'injected error':''];
}
function unmRemote(array $peer,string $command,int $timeout):array{
    preg_match("/migration-resolve '([^']+)'$/",$command,$m);
    $request=json_decode(base64_decode($m[1]??''),true);
    resolutionCheck(is_array($request),'remote command binding');
    $GLOBALS['remote'][]=$request;
    if(!$GLOBALS['remoteRunning'])return ['code'=>1,'stdout'=>'','stderr'=>'destination not running'];
    if(!empty($request['commit'])&&$GLOBALS['failCommit']){$GLOBALS['failCommit']=false;return ['code'=>1,'stdout'=>'','stderr'=>'reply lost'];}
    return ['code'=>0,'stderr'=>'','stdout'=>json_encode(['success'=>true,'hostId'=>$GLOBALS['ackHost'],'jobId'=>$request['jobId'],'vmUuid'=>$request['vmUuid'],'running'=>true,'committed'=>!empty($request['commit']),'cleanupScheduled'=>!empty($request['scheduleCleanup'])])];
}
function fixture(string $policy='retain'):string{
    $uuid='12345678-1234-4234-8234-'.bin2hex(random_bytes(6));$id='20260912-'.bin2hex(random_bytes(4));$dir=UNM_JOBS_DIR.'/'.$id;mkdir($dir);
    $GLOBALS['uuid']=$uuid;$GLOBALS['state']='shut off';$GLOBALS['name']='Squid Proxy';$GLOBALS['disk']='/mnt/pool/Squid Proxy/disk.img';
    $GLOBALS['commands']=[];$GLOBALS['remote']=[];$GLOBALS['auto']=true;$GLOBALS['inventoryFailure']=false;
    $GLOBALS['failRename']=false;$GLOBALS['failUndefine']=false;$GLOBALS['failCommit']=false;$GLOBALS['remoteRunning']=true;
    $GLOBALS['peerHost']='destination-host';$GLOBALS['ackHost']='destination-host';$GLOBALS['renames']=0;$GLOBALS['undefines']=0;
    $job=['id'=>$id,'state'=>'ATTENTION_REQUIRED','jobType'=>'migration','vm'=>'Squid Proxy','peerId'=>'peer1','peerName'=>'DEV02','request'=>['VM_UUID'=>$uuid,'SOURCE_CLEANUP_ACTION'=>$policy]];
    unmAtomicJson($dir.'/job.json',$job);unmAtomicJson($dir.'/remote-capabilities.json',['hostId'=>'destination-host']);
    file_put_contents($dir.'/source.xml',testXml($uuid,'Squid Proxy'));file_put_contents($dir.'/destination.xml',testXml($uuid,'Squid Proxy','/mnt/destination/Squid Proxy/disk.img'));
    return $id;
}
try{
    $id=fixture();$preview=unmResolveMigration($id);
    resolutionCheck($preview['sourcePolicy']==='retain'&&$preview['sourceName']==='Squid Proxy - Migrated to DEV02','preview preserves selected rename policy');
    resolutionCheck($GLOBALS['auto']&&$GLOBALS['renames']===0&&!is_file(UNM_JOBS_DIR.'/'.$id.'/manual-resolution.json'),'preview makes no source changes');
    $job=unmResolveMigration($id,true);
    resolutionCheck($job['state']==='COMPLETE_WITH_WARNINGS'&&$GLOBALS['name']==='Squid Proxy - Migrated to DEV02'&&!$GLOBALS['auto'],'retain rename completed');
    resolutionCheck($job['request']['SOURCE_CLEANUP_ACTION']==='retain','original policy unchanged');
    $count=count($GLOBALS['commands']);unmResolveMigration($id,true);
    resolutionCheck(count($GLOBALS['commands'])===$count&&$GLOBALS['renames']===1,'lost final response idempotent');
    foreach($GLOBALS['commands'] as $cmd)resolutionCheck(!in_array($cmd[1],['start','destroy','shutdown','define'],true),'resolution never starts/stops/redefines VM');
    $id=fixture();$GLOBALS['failRename']=true;
    resolutionReject(fn()=>unmResolveMigration($id,true),'rename reply lost retains unresolved state');
    resolutionCheck(unmLoadJson(UNM_JOBS_DIR.'/'.$id.'/job.json')['state']==='ATTENTION_REQUIRED','failed transaction stays actionable');
    unmResolveMigration($id,true);resolutionCheck($GLOBALS['renames']===1,'rename crash resume does not add another suffix');
    $id=fixture();$GLOBALS['failCommit']=true;
    resolutionReject(fn()=>unmResolveMigration($id,true),'remote commit reply lost');
    resolutionCheck($GLOBALS['renames']===0,'source policy waits for remote acknowledgement');
    unmResolveMigration($id,true);resolutionCheck($GLOBALS['renames']===1,'remote acknowledgement retry completes');
    $id=fixture('unregister');$GLOBALS['failUndefine']=true;
    resolutionReject(fn()=>unmResolveMigration($id,true),'unregister reply lost');
    resolutionCheck($GLOBALS['state']==='undefined','unregister side effect simulated');
    $job=unmResolveMigration($id,true);
    resolutionCheck($GLOBALS['undefines']===1&&$job['request']['SOURCE_CLEANUP_ACTION']==='unregister','journaled undefined source resumes without repeat unregistration');
    $id=fixture('unregister');$GLOBALS['state']='undefined';resolutionReject(fn()=>unmResolveMigration($id,true),'unexpected undefined source');
    $id=fixture();$GLOBALS['inventoryFailure']=true;resolutionReject(fn()=>unmResolveMigration($id,true),'unavailable libvirt not treated as off');
    $id=fixture();$GLOBALS['state']='running';resolutionReject(fn()=>unmResolveMigration($id,true),'both copies running');
    $id=fixture();$GLOBALS['disk']='/mnt/other/disk.img';resolutionReject(fn()=>unmResolveMigration($id,true),'source disk substitution');
    $id=fixture();$GLOBALS['remoteRunning']=false;resolutionReject(fn()=>unmResolveMigration($id,true),'destination unavailable');
    resolutionCheck($GLOBALS['auto']&&$GLOBALS['renames']===0,'remote failure makes no source changes');
    $id=fixture();$GLOBALS['peerHost']='different-host';resolutionReject(fn()=>unmResolveMigration($id,true),'peer host changed');
    $id=fixture();$GLOBALS['ackHost']='different-host';resolutionReject(fn()=>unmResolveMigration($id,true),'wrong host acknowledgement');
    $id=fixture();unmAtomicJson(UNM_OWNERSHIP_DIR.'/'.$GLOBALS['uuid'].'.json',['jobId'=>'another-job']);resolutionReject(fn()=>unmResolveMigration($id,true),'unrelated ownership marker');
    $id=fixture();$previous=['jobId'=>'previous-migration','vmUuid'=>$GLOBALS['uuid'],'ownerHostId'=>'source-host','state'=>'owned','updatedAt'=>'previous-time'];
    unmAtomicJson(UNM_OWNERSHIP_DIR.'/'.$GLOBALS['uuid'].'.json',$previous);
    unmAtomicJson(UNM_JOBS_DIR.'/'.$id.'/source-ownership-before.json',$previous);
    unmResolveMigration($id,true);resolutionCheck($GLOBALS['renames']===1,'unchanged recorded locally-owned marker can be superseded');
    $id=fixture();$previous['vmUuid']=$GLOBALS['uuid'];
    unmAtomicJson(UNM_JOBS_DIR.'/'.$id.'/source-ownership-before.json',$previous);
    $previous['updatedAt']='changed-after-preflight';unmAtomicJson(UNM_OWNERSHIP_DIR.'/'.$GLOBALS['uuid'].'.json',$previous);
    resolutionReject(fn()=>unmResolveMigration($id,true),'prior marker changed after preflight');
    $id=fixture('delete');resolutionReject(fn()=>unmResolveMigration($id,true),'legacy missing deletion manifest');
    resolutionCheck($GLOBALS['renames']===0&&$GLOBALS['auto'],'missing deletion evidence blocked before writes');
    $xml=testXml($GLOBALS['uuid'],'Squid Proxy');$usbFixed=str_replace('device="2"','device="3"',$xml);
    unmResolutionAssertDomain($xml,$usbFixed,$GLOBALS['uuid']);
    unmResolutionAssertDomain($xml,str_replace('vda','hdc',$xml),$GLOBALS['uuid']);
    $formatted=str_replace('<source file=','<driver type="raw"/><source file=',$xml);
    resolutionReject(fn()=>unmResolutionAssertDomain($formatted,str_replace('type="raw"','type="qcow2"',$formatted),$GLOBALS['uuid']),'disk format substitution');
    resolutionReject(fn()=>unmResolutionAssertDomain($xml,testXml('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa','Squid Proxy'),$GLOBALS['uuid']),'destination UUID substitution');
    // Exercise the destination implementation directly, including the allowed
    // corrected USB configuration and refusal of unrelated writable paths.
    $id=fixture();$GLOBALS['state']='running';
    mkdir(UNM_BOOT_DIR.'/incoming');mkdir(UNM_BOOT_DIR.'/incoming/'.$id);
    $incoming=testXml($GLOBALS['uuid'],'Squid Proxy');
    file_put_contents(UNM_BOOT_DIR.'/incoming/'.$id.'/destination.xml',$incoming);
    $request=['jobId'=>$id,'vmUuid'=>$GLOBALS['uuid'],'vmName'=>'Squid Proxy','sourceVmName'=>'Squid Proxy - Migrated to DEV02','sourcePolicy'=>'retain','sourceHostId'=>'destination-host','destinationHostId'=>'source-host','destinationXmlSha256'=>hash('sha256',$incoming),'commit'=>false];
    $check=unmResolutionDestination($request);resolutionCheck($check['running']&&!$check['committed'],'destination read-only check');
    resolutionCheck(!is_file(UNM_OWNERSHIP_DIR.'/'.$GLOBALS['uuid'].'.json'),'destination preview has no marker side effect');
    $request['commit']=true;unmResolutionDestination($request);unmResolutionDestination($request);
    $marker=unmLoadJson(UNM_OWNERSHIP_DIR.'/'.$GLOBALS['uuid'].'.json');
    resolutionCheck($marker['sourcePolicy']==='retain'&&$marker['sourceVmName']==='Squid Proxy - Migrated to DEV02','destination records original source policy and retained name');
    $GLOBALS['scheduled']=[];$request['sourcePolicy']='delete';$request['scheduleCleanup']=true;
    unmResolutionDestination($request);
    resolutionCheck(count($GLOBALS['scheduled'])===1&&$GLOBALS['scheduled'][0]['soakSeconds']===300,'delete resolution preserves full five-minute validation');
    $cleanup=['jobId'=>$id,'vmUuid'=>$GLOBALS['uuid'],'sourceHostId'=>'destination-host','destinationHostId'=>'source-host','state'=>'complete'];
    unmAtomicJson(UNM_BOOT_DIR.'/incoming/'.$id.'/source-cleanup-request.json',$cleanup);
    unmResolutionDestination($request);resolutionCheck(count($GLOBALS['scheduled'])===1,'completed cleanup is never rescheduled after reply loss');
    $cleanup['state']='validating';$cleanup['pid']=0;unmAtomicJson(UNM_BOOT_DIR.'/incoming/'.$id.'/source-cleanup-request.json',$cleanup);
    unmResolutionDestination($request);resolutionCheck(count($GLOBALS['scheduled'])===2&&$GLOBALS['scheduled'][1]['soakSeconds']===300,'dead watcher restarts with full validation period');
    $request['scheduleCleanup']=false;
    $GLOBALS['disk']='/mnt/other/disk.img';resolutionReject(fn()=>unmResolutionDestination($request),'destination writable path substitution');
    $GLOBALS['disk']='/mnt/pool/Squid Proxy/disk.img';$GLOBALS['state']='shut off';resolutionReject(fn()=>unmResolutionDestination($request),'destination stopped');
    $GLOBALS['state']='running';$request['destinationXmlSha256']=str_repeat('0',64);resolutionReject(fn()=>unmResolutionDestination($request),'incoming XML binding');
    $request['destinationXmlSha256']=hash('sha256',$incoming);$request['sourceHostId']='source-host';resolutionReject(fn()=>unmResolutionDestination($request),'destination self pairing');
    $request['sourceHostId']='destination-host';$GLOBALS['peerHost']='unrelated';resolutionReject(fn()=>unmResolutionDestination($request),'missing reciprocal pairing');
    foreach($GLOBALS['commands'] as $cmd)resolutionCheck(!in_array($cmd[1],['start','destroy','shutdown','define','domrename','undefine','autostart'],true),'destination resolver never changes libvirt configuration');
    echo "Migration resolution transaction regressions passed\n";
}finally{
    $paths=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($paths as $entry){if($entry->isDir())rmdir($entry->getPathname());else unlink($entry->getPathname());}
    rmdir($root);
}
