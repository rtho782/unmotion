<?php
// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
declare(strict_types=1);
require_once __DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/lib.php';

$root=sys_get_temp_dir().'/unmotion-native-grant-'.bin2hex(random_bytes(8));
if(!mkdir($root.'/native-admissions',0700,true))throw new RuntimeException('Cannot create isolated admission fixture.');
$uuid='74200000-1111-4111-8111-000000000006';$id='repl-'.str_repeat('a',24);$boot='74200000-1111-4111-8111-000000000099';
$record=['vmUuid'=>$uuid,'replicationId'=>$id];$path=$root.'/native-admissions/'.$uuid.'.json';
$valid=['schemaVersion'=>1,'vmUuid'=>$uuid,'replicationId'=>$id,'side'=>'source','term'=>1,'bootId'=>$boot,'stage'=>'PREPARING','expiresAt'=>time()+120];
$checks=0;
$check=static function(bool $ok,string $why)use(&$checks):void {if(!$ok)throw new RuntimeException($why);$checks++;};
$call=static fn()=>unmRecoveryAssertNoNativeStartInFlight($record,$root,$boot);
$reject=static function(array|string $value,string $why)use($path,$call,$check):void {
    file_put_contents($path,is_array($value)?json_encode($value,JSON_THROW_ON_ERROR):$value);
    try{$call();}catch(RuntimeException $e){$check(true,$why);return;}
    throw new RuntimeException('Unsafe source grant accepted: '.$why);
};
try{
    $call();$check(true,'no pending native start');
    $reject($valid,'preparing launch');
    $reject(array_replace($valid,['stage'=>'STARTING']),'accepted launch');
    $reject(array_replace($valid,['stage'=>'STARTING','expiresAt'=>time()-600]),'expired clock is not proof a launch ended');
    $reject(array_replace($valid,['stage'=>'UNKNOWN']),'unknown stage');
    $reject(array_replace($valid,['bootId'=>'']),'unknown boot');
    $reject(array_replace($valid,['term'=>'1']),'malformed authority term');
    $reject(array_replace($valid,['vmUuid'=>$boot]),'conflicting UUID');
    $reject(array_replace($valid,['side'=>'destination']),'wrong side');
    $reject('{broken','corrupt admission');
    file_put_contents($path,json_encode(array_replace($valid,['bootId'=>'74200000-1111-4111-8111-000000000098'])));
    $call();$check(true,'previous-boot processes cannot survive');
    file_put_contents($path,json_encode(array_replace($valid,['stage'=>'RUNNING'])));
    $call();$check(true,'settled launch proceeds to mandatory real VM-off check');
    $lines=file(__DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/lib.php');
    foreach(['unmRecoveryRemoteGrant','unmRecoveryRemoteClaimRenew','unmRecoveryRemoteActivationCommit'] as $name){
        $reflection=new ReflectionFunction($name);$body=implode('',array_slice($lines,$reflection->getStartLine()-1,$reflection->getEndLine()-$reflection->getStartLine()+1));
        $guard=strpos($body,'unmRecoveryAssertNoNativeStartInFlight($record)');$state=strpos($body,'unmRecoveryVmState(');
        $check($guard!==false&&$state!==false&&$guard<$state,$name.' must guard before libvirt observation');
        $check(strpos($body,'unmRecoveryRequireNativeFenceProof();')!==false&&strpos($body,'unmRecoveryRequireNativeFenceProof();')<$guard,$name.' requires active native hook even for previously armed policies');
        if($name==='unmRecoveryRemoteGrant')$check($guard<strpos($body,'unmRecoveryGrantMatches('),'idempotent grant must not bypass native admission');
    }
    $reflection=new ReflectionFunction('unmRecoveryRequestManagedStart');$body=implode('',array_slice($lines,$reflection->getStartLine()-1,$reflection->getEndLine()-$reflection->getStartLine()+1));
    $check(str_contains($body,'unmRecoveryAcquireVmLock(')&&str_contains($body,'unmRecoveryInterlockStatus(')&&str_contains($body,'unmRecoveryAssertNoNativeStartInFlight('),'manual managed start retains operation and native guards');
    $check(strpos($body,'unmRecoveryReconcileSourceStartAuthorization(')<strpos($body,'unmRecoveryQueueManagedStart('),'manual start obtains fresh peer authority before queueing');
    $check(!str_contains($body,"['desiredAutostart']="),'manual start does not change managed autostart');
    $api=(string)file_get_contents(__DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/api.php');
    $check((bool)preg_match("/case 'recoveryStartSource':\\s+requirePostMutation\\(\\);requireRecoveryDirection\\('source'\\);/",$api),'manual source start must use protected POST and source direction');
    $js=(string)file_get_contents(__DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/js/unmotion.js');
    $check(str_contains($js,"recoveryActionAllowed(payload,'canStartSource')")&&str_contains($js,"recoveryMutation('recoveryStartSource',{},this,'Starting')"),'manual start button uses current eligibility and protected mutation helper');
    echo 'Native source-grant race regressions passed ('.$checks.').'."\n";
}finally{
    if(is_file($path)||is_link($path))unlink($path);
    rmdir($root.'/native-admissions');rmdir($root);
}
