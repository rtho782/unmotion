<?php
declare(strict_types=1);
// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
$root=sys_get_temp_dir().'/unmotion-cutover-controls-'.bin2hex(random_bytes(6));mkdir($root,0700);
define('UNM_JOBS_DIR',$root.'/jobs');mkdir(UNM_JOBS_DIR,0700);
function unmLoadJson(string $path,array $default=[]):array{return is_file($path)?(json_decode(file_get_contents($path),true)?:$default):$default;}
function unmSeedPath(string $id):string{return $GLOBALS['root'].'/'.$id;}
function unmSeed(string $id):array{return unmLoadJson(unmSeedPath($id).'/seed.json');}
function cutoverCheck(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
require __DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/seed-concurrency.php';
$uuid='74100000-1111-4111-8111-000000000019';$id='seed-'.bin2hex(random_bytes(12));
$job=['id'=>'test-cutover','state'=>'STARTING','request'=>['VM_UUID'=>$uuid,'WARM_SEED_ID'=>$id,'SOURCE_CLEANUP_ACTION'=>'retain']];
$dir=UNM_JOBS_DIR.'/test-cutover';mkdir($dir,0700);mkdir(unmSeedPath($id),0700);
file_put_contents(unmSeedPath($id).'/seed.json',json_encode(['id'=>$id,'vmUuid'=>$uuid,'state'=>'READY']));
try{
    foreach(['STARTING','PREFLIGHT','SHUTTING_DOWN','TRANSFERRING','TRANSFER_VERIFIED','HOST_STATE','STARTING_DESTINATION','OWNERSHIP_HANDOFF','ATTENTION_REQUIRED','INTERRUPTED','UNKNOWN_NEW_STATE'] as $state){
        $job['state']=$state;file_put_contents($dir.'/job.json',json_encode($job));
        cutoverCheck(unmActiveVmJob($uuid)!==null,'active/uncertain state '.$state);
        foreach([fn()=>unmSeedAssertIdle($id,$uuid),fn()=>unmRemoveSeed($id)] as $action){
            $blocked=false;try{$action();}catch(RuntimeException $e){$blocked=str_contains($e->getMessage(),'job controls');}
            cutoverCheck($blocked,'queued cutover must block update/remove before a worker lock exists');
        }
    }
    foreach(['FAILED','CANCELLED','COMPLETE','COMPLETE_WITH_WARNINGS','COMPLETE_CLEANED'] as $state){$job['state']=$state;cutoverCheck(unmActiveVmJob($uuid,[$job])===null,'terminal state '.$state);}
    $job['state']='COMPLETE';$job['request']['SOURCE_CLEANUP_ACTION']='delete';cutoverCheck(unmActiveVmJob($uuid,[$job])!==null,'pending source deletion');
    $job['state']='STARTING';cutoverCheck(unmActiveVmJob('other-uuid',[$job])===null,'unrelated VM remains usable');
    file_put_contents($dir.'/job.json',json_encode($job));cutoverCheck(unmSeed($id)['state']==='READY','busy flag must not alter worker seed state');
    echo "Cutover admission, queued-job guards, pending cleanup and unrelated-VM control regressions passed.\n";
}finally{
    unlink($dir.'/job.json');rmdir($dir);rmdir(UNM_JOBS_DIR);unlink(unmSeedPath($id).'/seed.json');rmdir(unmSeedPath($id));rmdir($root);
}
