<?php
// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
declare(strict_types=1);
namespace LiveUpgradeFixture;
// Run the actual guard against a virtual filesystem/process table. No host writes.
$src=\file_get_contents(__DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/process-upgrade.php');
eval('namespace LiveUpgradeFixture; use \\RuntimeException;'.substr($src,strpos($src,'function unmProcessUpgradeInventory(')));
$files=[];$links=[];$processes=[];$state='running';$listed=true;$checks=0;$commands=[];$codes=[];
function file_get_contents(string $p):string|false {global $files;return $files[$p]??false;}
function is_file(string $p):bool {global $files;return isset($files[$p]);}
function is_link(string $p):bool {global $links;return isset($links[$p]);}
function hash_file(string $alg,string $p):string|false {global $files;return isset($files[$p])?hash($alg,$files[$p]):false;}
function readlink(string $p):string|false {global $processes;if(preg_match('~/proc/(\d+)/exe$~',$p,$m))return $processes[(int)$m[1]]['executable']??false;return false;}
function glob(string $pattern):array {global $files;$re='~^'.str_replace(['\*','\?'],['[^/]*','[^/]'],preg_quote($pattern,'~')).'$~';if($pattern==='/proc/[0-9]*/cmdline')return array_values(array_filter(array_keys($files),static fn($p)=>preg_match('~^/proc/\d+/cmdline$~',$p)));return array_values(array_filter(array_keys($files),static fn($p)=>preg_match($re,$p)));}
function unmProcessSnapshot(int $pid):?array {global $processes;return $processes[$pid]??null;}
function unmProcessIdentityMatches(array $p):bool {global $processes;return ($processes[$p['pid']]??null)===$p;}
function proc_open(array $args,array $fds,?array &$pipes){global $uuid,$state,$listed,$commands,$codes;$commands[]=$args;if(array_slice($args,0,5)!==['timeout','-k','2','15','virsh'])throw new \LogicException('Unexpected command');$op=array_slice($args,5);$out=match($op){['list','--all','--uuid']=>$listed?$uuid."\n":'', ['domstate',$uuid]=>$state."\n",default=>throw new \LogicException('Mutating command')};$p=fopen('php://temp','r+');$codes[(int)$p]=0;$pipes=[1=>fopen('php://temp','r+')];fwrite($pipes[1],$out);rewind($pipes[1]);return $p;}
function proc_close($p):int {global $codes;$c=$codes[(int)$p];unset($codes[(int)$p]);fclose($p);return $c;}
function check(bool $v,string $m):void {global $checks;if(!$v)throw new \RuntimeException($m);++$checks;}
function rejects(callable $f,string $m):void {try{$f();}catch(\RuntimeException){check(true,$m);return;}throw new \LogicException('Unexpected acceptance: '.$m);}
function put(string $p,array $v):void {global $files;$files[$p]=json_encode($v,JSON_THROW_ON_ERROR);}
$base='/fixture';$inc='/usr/local/emhttp/plugins/unmotion/include/';$run='/run/unmotion/lifecycle';$boot='33333333-3333-4333-8333-000000000001';$local='11111111-1111-4111-8111-000000000001';$other='22222222-2222-4222-8222-000000000001';$uuid='74200000-1111-4111-8111-000000000006';$id='live-'.str_repeat('a',24);
$r=['schemaVersion'=>1,'vmUuid'=>$uuid,'vmName'=>'Squid Proxy Upgrade','jobId'=>$id,'state'=>'OWNER','role'=>'destination','sourceHostId'=>$other,'destinationHostId'=>$local,'bootId'=>$boot,'expiresAt'=>1];
$q=['schema'=>1,'pid'=>5500,'startTicks'=>'1234','executable'=>'/usr/bin/qemu-system-x86_64','bootId'=>$boot,'argv'=>['qemu-system-x86_64','-uuid',$uuid]];
$daemon=['schema'=>1,'pid'=>2200,'startTicks'=>'567','executable'=>'/usr/sbin/libvirtd','bootId'=>$boot,'argv'=>['libvirtd','-d']];$processes=[5500=>$q,2200=>$daemon];
$files[$base.'/host_id']=$local;$files['/proc/sys/kernel/random/boot_id']=$boot;$files['/proc/5500/cmdline']=implode("\0",$q['argv'])."\0";$files['/run/libvirt/qemu/'.$r['vmName'].'.pid']='5500';
$target=[];foreach(unmProcessFencePaths() as $p){$files[$p]='target bytes '.$p;$target[$p]=hash('sha256',$files[$p]);}
put($base.'/live-managed/'.$uuid.'.json',array_intersect_key($r,array_flip(['schemaVersion','vmUuid','jobId'])));put($base.'/live-authority/'.$uuid.'.json',$r);put($base.'/live-migration/jobs/'.$id.'/job.json',['state'=>'COMPLETE','id'=>$id,'vmUuid'=>$uuid]);
$a=['jobId'=>$id,'bootId'=>$boot,'phase'=>'RUNNING','qemu'=>['pid'=>5500,'startTicks'=>'1234','exe'=>$q['executable'],'bootId'=>$boot,'uuid'=>$uuid]];put($run.'/live-admissions/'.$uuid.'.json',$a);
$hash='';foreach(['live-native-fence.php','live-firmware.php','live-storage.php','live-zvol-native.php','live-tpm.php','xml-canonical.php'] as $p)$hash.=$target[$inc.$p];
$proof=['schemaVersion'=>1,'daemon'=>['pid'=>2200,'startTicks'=>'567','exe'=>$daemon['executable'],'bootId'=>$boot],'hookSha256'=>$target['/etc/libvirt/hooks/qemu.d/50-unmotion-recovery'],'helperSha256'=>$target[$inc.'native-fence.php'],'liveHelperSha256'=>hash('sha256',$hash)];put($run.'/native-hook-proof.json',$proof);
$good=$files;$call=static fn()=>unmProcessAssertManagedGuestsStopped($base,false,$target);
$call();check($files===$good,'Settled owner upgrades without rewriting any admission, authority or history.');
foreach(['running','idle','paused'] as $state){$call();check(true,'Existing power state preserved: '.$state);}$state='running';
$copy=$target;unset($copy[array_key_first($copy)]);rejects(fn()=>unmProcessAssertManagedGuestsStopped($base,false,$copy),'Missing target dependency');$copy=$target;$copy['/unexpected']='x';rejects(fn()=>unmProcessAssertManagedGuestsStopped($base,false,$copy),'Extra target path');
foreach($target as $p=>$sha){$files[$p].='changed';rejects($call,'Changed hook dependency: '.$p);$files=$good;$links[$p]=true;rejects($call,'Symlink hook dependency');$links=[];unset($files[$p]);rejects($call,'Missing hook dependency');$files=$good;}
foreach(['MOVING','RECEIVING','RETIRED','ABORTED'] as $s){$bad=$r;$bad['state']=$s;if(in_array($s,['MOVING','RETIRED'],true)){$bad['role']='source';$bad['sourceHostId']=$local;$bad['destinationHostId']=$other;}put($base.'/live-authority/'.$uuid.'.json',$bad);rejects($call,'Running non-owner/in-flight VM blocked: '.$s);$files=$good;}
$bad=$r;$bad['workflow']='ordinary-pending';$bad['handbackNonce']=str_repeat('c',32);put($base.'/live-authority/'.$uuid.'.json',$bad);rejects($call,'Pending hand-back blocked');$files=$good;
foreach(['QUEUED','COPYING','COMMITTING','SOURCE_RETIRED','ATTENTION',''] as $s){put($base.'/live-migration/jobs/'.$id.'/job.json',['state'=>$s]);rejects($call,'Unfinished journal blocked');$files=$good;}
foreach(['jobId','bootId','phase','qemu'] as $key){$bad=$a;$bad[$key]=$key==='qemu'?[]:'wrong';put($run.'/live-admissions/'.$uuid.'.json',$bad);rejects($call,'Wrong existing admission '.$key);$files=$good;}
foreach(['pid','startTicks','exe','bootId','uuid'] as $key){$bad=$a;$bad['qemu'][$key]=$key==='pid'?5501:'wrong';put($run.'/live-admissions/'.$uuid.'.json',$bad);rejects($call,'Wrong QEMU lifetime '.$key);$files=$good;}
foreach([$run.'/native-hook-proof.json',$run.'/live-admissions/'.$uuid.'.json'] as $p){unset($files[$p]);rejects($call,'Missing existing proof');$files=$good;$files[$p]='{broken';rejects($call,'Corrupt existing proof');$files=$good;$links[$p]=true;rejects($call,'Symlink existing proof');$links=[];}
foreach(['hookSha256','helperSha256','liveHelperSha256','daemon'] as $key){$bad=$proof;$bad[$key]=$key==='daemon'?[]:'wrong';put($run.'/native-hook-proof.json',$bad);rejects($call,'Stale hook proof '.$key);$files=$good;}
$processes[2200]['startTicks']='568';rejects($call,'Libvirt PID reuse');$processes[2200]=$daemon;$processes[5500]['startTicks']='1235';rejects($call,'QEMU PID reuse');$processes[5500]=$q;
$listed=false;rejects($call,'Running QEMU hidden from libvirt');$listed=true;$state='shut off';rejects($call,'Live QEMU/libvirt stopped disagreement');$state='running';
put($base.'/replications/repl-'.str_repeat('b',24).'/recovery.json',['vmUuid'=>$uuid,'state'=>'STANDBY','armed'=>true]);rejects($call,'Recovery-managed overlap cannot inherit live exception');$files=$good;
$r['workflow']='ordinary';put($base.'/live-authority/'.$uuid.'.json',$r);$call();check(true,'Settled ordinary owner retains compatible UUID fence');$files=$good;
rejects(fn()=>unmProcessAssertManagedGuestsStopped($base,false,[]),'Unspecified target never adopts a running VM');
check($files===$good,'Rejected checks never rewrote authority/admission');check(!$codes,'No command handles leaked');
echo 'Running-owner upgrade compatibility, lifetime, retained-fence and rejection regressions passed ('.$checks.").\n";
