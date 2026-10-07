<?php
// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
declare(strict_types=1);
namespace UpgradeGuardFixture;

// Exercise the real preinstall authority guard, replacing only process launch.
// No virsh command, daemon stop, installed state or live VM is touched.
$source=file_get_contents(__DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/process-upgrade.php');
$start=strpos($source,'function unmProcessUpgradeInventory(');
if($start===false)throw new \RuntimeException('Upgrade helper boundary changed.');
eval('namespace UpgradeGuardFixture; use \\RuntimeException;'.substr($source,$start));
$commands=[];$replies=[];$exitCodes=[];$checks=0;
function proc_open(array $command,array $descriptors,?array &$pipes) {
    global $commands,$replies,$exitCodes;
    $commands[]=$command;
    if(array_slice($command,0,5)!==['timeout','-k','2','15','virsh'])throw new \RuntimeException('Unexpected process command.');
    if(!$replies)throw new \RuntimeException('Unexpected additional libvirt lookup.');
    [$expected,$output,$code]=array_shift($replies);
    if(array_slice($command,5)!==$expected)throw new \RuntimeException('Unexpected libvirt operation.');
    $process=fopen('php://temp','r+');$exitCodes[(int)$process]=$code;
    $pipes=[1=>fopen('php://temp','r+')];fwrite($pipes[1],$output);rewind($pipes[1]);return $process;
}
function proc_close($process): int {
    global $exitCodes;$key=(int)$process;$code=$exitCodes[$key];unset($exitCodes[$key]);fclose($process);return $code;
}
function check(bool $ok,string $message): void {global $checks;if(!$ok)throw new \RuntimeException($message);++$checks;}
function rejects(callable $call,string $message): void {
    try{$call();}catch(\RuntimeException $e){if(str_starts_with($e->getMessage(),'Unexpected '))throw $e;check(true,$message);return;}throw new \RuntimeException('Accepted: '.$message);
}
function writeJson(string $path,array $value): void {if(!is_dir(dirname($path)))mkdir(dirname($path),0700,true);file_put_contents($path,json_encode($value,JSON_THROW_ON_ERROR));}
function removeOwnedTree(string $path): void {foreach(scandir($path)?:[] as $name){if($name==='.'||$name==='..')continue;$entry=$path.'/'.$name;is_dir($entry)&&!is_link($entry)?removeOwnedTree($entry):unlink($entry);}rmdir($path);}
$root=sys_get_temp_dir().'/unmotion-upgrade-managed-'.bin2hex(random_bytes(8));mkdir($root,0700);
$uuid='74200000-1111-4111-8111-000000000006';$id='repl-'.str_repeat('a',24);
$marker=['schemaVersion'=>1,'replicationId'=>$id,'vmUuid'=>$uuid,'side'=>'source'];
$record=['replicationId'=>$id,'vmUuid'=>$uuid,'side'=>'source','state'=>'REPLICATION_ONLY','armed'=>false,'managedAutostart'=>false,'term'=>0];
$markerPath=$root.'/native-managed/'.$uuid.'.json';$recordPath=$root.'/replications/'.$id.'/recovery.json';
try {
    unmProcessAssertManagedGuestsStopped($root);check(!$commands,'No authority records require no libvirt lookup.');
    writeJson($markerPath,$marker);rejects(fn()=>unmProcessAssertManagedGuestsStopped($root),'Missing exact marked authority is rejected.');check(!$commands,'Missing authority rejected before libvirt.');
    file_put_contents($markerPath,'{broken');rejects(fn()=>unmProcessAssertManagedGuestsStopped($root),'Corrupt native marker rejected.');
    writeJson($markerPath,$marker);writeJson($recordPath,$record);
    unmProcessAssertManagedGuestsStopped($root);check(!$commands,'Exact inactive term-zero authority needs no libvirt lookup.');
    file_put_contents($recordPath,'{broken');rejects(fn()=>unmProcessAssertManagedGuestsStopped($root),'Corrupt marked authority rejected.');check(!$commands,'Corrupt authority rejected before libvirt.');
    writeJson($recordPath,$record+['claim'=>['kind'=>'coordinated']]);
    $replies=[[['list','--all','--uuid'],$uuid."\n",0],[['domstate',$uuid],"running\n",0]];
    rejects(fn()=>unmProcessAssertManagedGuestsStopped($root),'Inactive state with outstanding claim still requires stopped guest.');check(!$replies,'Outstanding claim queries exact VM state.');
    $armed=array_replace($record,['state'=>'STANDBY','armed'=>true,'managedAutostart'=>true,'term'=>1]);writeJson($recordPath,$armed);
    foreach(['running','paused','crashed','unknown',''] as $state){
        $replies=[[['list','--all','--uuid'],$uuid."\n",0],[['domstate',$uuid],$state."\n",0]];
        rejects(fn()=>unmProcessAssertManagedGuestsStopped($root),'Managed non-shut-off state rejected: '.$state);check(!$replies,'Exact state lookup consumed.');
    }
    $replies=[[['list','--all','--uuid'],$uuid."\n",0],[['domstate',$uuid],"shut off\n",0]];
    unmProcessAssertManagedGuestsStopped($root);check(!$replies,'Proven shut-off managed VM permits upgrade.');
    $replies=[[['list','--all','--uuid'],"\n",0]];unmProcessAssertManagedGuestsStopped($root);check(!$replies,'Successful empty inventory permits absent managed definition.');
    $replies=[[['list','--all','--uuid'],'',1]];rejects(fn()=>unmProcessAssertManagedGuestsStopped($root),'Failed inventory rejected.');
    $replies=[[['list','--all','--uuid'],"invalid-uuid\n",0]];rejects(fn()=>unmProcessAssertManagedGuestsStopped($root),'Malformed successful inventory rejected.');
    $replies=[[['list','--all','--uuid'],$uuid."\n",0],[['domstate',$uuid],'',1]];rejects(fn()=>unmProcessAssertManagedGuestsStopped($root),'Failed state lookup rejected.');
    unlink($recordPath);$destination=array_replace($record,['side'=>'destination']);writeJson($markerPath,array_replace($marker,['side'=>'destination']));
    writeJson($root.'/replicas/peer-one/'.$id.'/recovery.json',$destination);writeJson($root.'/replicas/peer-two/'.$id.'/recovery.json',$destination);
    $before=count($commands);rejects(fn()=>unmProcessAssertManagedGuestsStopped($root),'Ambiguous marked destination authority rejected.');check(count($commands)===$before,'Ambiguous authority rejected before libvirt.');
    foreach($commands as $command)check(in_array($command[5],['list','domstate'],true),'Guard never issues a mutating virsh command.');
    check(!$exitCodes,'Mocked process handles closed.');
    echo 'Managed upgrade guard regressions passed ('.$checks.").\n";
} finally {removeOwnedTree($root);}
