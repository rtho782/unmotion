<?php
declare(strict_types=1);
require_once __DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/lib.php';
$source=file_get_contents(__DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/api.php');
// Execute the actual routing code in a subprocess. Replace only its library
// import with harmless sentinels; no host state or real plugin is touched.
$source=str_replace("require_once __DIR__ . '/include/lib.php';",'function unmEnsureDirs(): void {} function unmClearJobHistory(): int {return 7;}', $source);
$fixture=tempnam(sys_get_temp_dir(),'unmotion-api-');file_put_contents($fixture,$source);
$requestFile=tempnam(sys_get_temp_dir(),'unmotion-request-');
$actions=['saveSettings','pairPeer','testPeer','removePeer','breakPairing','startClone','startMigration','resumeJob','cancelJob','removeJob','clearJobs','createReplication','updateReplication','setReplicationEnabled','runReplicationNow','removeReplication','prepareWarm','updateWarm','resumeWarm','removeWarm','recoveryArm','recoveryDisarm','recoveryStartSource','recoveryHold','recoveryHoldRelease','recoveryEvidence','recoveryRenewClaim','recoveryActivate','recoveryStartActivation','recoveryRetryCheckpoint','recoveryStopActivation','recoveryRemoveActivation','resolveMigration'];
try {
    foreach($actions as $action)foreach(['GET','HEAD','OPTIONS',''] as $method){
        $code='$_SERVER["REQUEST_METHOD"]='.var_export($method,true).';$_GET["action"]='.var_export($action,true).';require '.var_export($fixture,true).';';
        file_put_contents($requestFile,'<?php '.$code);$r=unmRun([PHP_BINARY,$requestFile],null,10);$reply=json_decode($r['stdout'],true);
        if(($reply['success']??null)!==false||($reply['error']??'')!=='This action requires a CSRF-protected POST request.')throw new RuntimeException($action.' '.$method.' bypassed the POST gate: '.$r['stdout'].$r['stderr']);
    }
    file_put_contents($requestFile,'<?php $_SERVER["REQUEST_METHOD"]="POST";$_POST["action"]="clearJobs";require '.var_export($fixture,true).';');$r=unmRun([PHP_BINARY,$requestFile],null,10);
    if(json_decode($r['stdout'],true)!==['success'=>true,'removed'=>7])throw new RuntimeException('Valid POST history deletion failed: '.$r['stdout'].$r['stderr']);
    echo count($actions)." persistent mutation routes reject GET/HEAD/OPTIONS/missing methods; POST history deletion passed. Unraid supplies token validation.\n";
}finally{unlink($fixture);unlink($requestFile);}
