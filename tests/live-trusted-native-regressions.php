<?php
// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
declare(strict_types=1);
require __DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/lib.php';
$checks=0;function nativeOk(bool $b,string $m):void{global $checks;if(!$b)throw new RuntimeException($m);$checks++;}
function nativeDeny(callable $f,string $m):void{try{$f();}catch(Throwable){nativeOk(true,$m);return;}throw new RuntimeException($m);}
$j=['schemaVersion'=>2,'nativeHandoverVersion'=>1,'id'=>'live-'.str_repeat('a',24),'vmUuid'=>'05200001-1111-4111-8111-000000000001','role'=>'destination','bootId'=>'boot','sshCipher'=>'aes128-gcm@openssh.com','spec'=>['disks'=>[['path'=>'/mnt/cache/Squid Proxy/disk.raw','target'=>'vda','format'=>'raw','bytes'=>1048576]]]];
unmLiveValidateJobMode($j);nativeOk(true,'Known native schema/mode accepted');unmLiveValidateJobMode(['schemaVersion'=>1]);nativeOk(true,'Legacy records remain legacy');
unmLiveRequireNativeWorker($j);nativeOk(true,'Native jobs may launch');nativeDeny(fn()=>unmLiveRequireNativeWorker(['schemaVersion'=>1]),'Legacy queued jobs remain readable but cannot reserve a new native receiver');
$engine=file_get_contents(__DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/live-engine.php');$worker=substr($engine,strpos($engine,'function unmLiveRunWorker'));
nativeOk(strpos($worker,'unmLiveRequireNativeWorker($j)')<strpos($worker,'unmLivePreview('),'Legacy launch is rejected before preflight or any reservation/key mutation');
foreach([['schemaVersion'=>1],['nativeHandoverVersion'=>2],['nativeHandoverVersion'=>null],['sshCipher'=>'none'],['schemaVersion'=>3]] as $change)nativeDeny(fn()=>unmLiveValidateJobMode(array_replace($j,$change)),'Changed native mode never falls back');
$args=unmLiveTrustedMigrationArgs($j);foreach(['--suspend','--unsafe','--tunnelled','qemu+ext:///system?command=/usr/local/sbin/unmotion-live-client'] as $bad)nativeOk(!in_array($bad,$args,true),'Native path excludes '.$bad);
foreach(['--live','--persistent','--copy-storage-all','--change-protection','--abort-on-error','--migrate-disks-detect-zeroes','qemu+unix:///system?socket='.unmLiveRuntime($j['id']).'/control.sock'] as $good)nativeOk(in_array($good,$args,true),'Native argument '.$good);
$q=['pid'=>42,'startTicks'=>'123','bootId'=>'boot'];$r=['jobId'=>$j['id'],'state'=>'RECEIVING','role'=>'destination','bootId'=>'boot'];$a=['jobId'=>$j['id'],'bootId'=>'boot','phase'=>'RUNNING','qemu'=>$q];
$proof=fn($job,$record,$ad,$process,$boot,$migration,$state)=>unmLiveTrustedCompletionEvidence($job,$record,$ad,$process,$boot,$migration,$state);
nativeOk($proof($j,$r,$a,$q,'boot','completed','running'),'Completed admitted native receiver accepted while restart-fenced');
foreach(['none','setup','active','cancelled','failed','postcopy-active'] as $state)nativeOk(!$proof($j,$r,$a,$q,'boot',$state,'running'),'Running alone is not native completion: '.$state);
foreach(['jobId'=>'other','bootId'=>'old','phase'=>'PREPARED','qemu'=>['pid'=>43]] as $k=>$v)nativeOk(!$proof($j,$r,array_replace($a,[$k=>$v]),$q,'boot','completed','running'),'Changed admission '.$k.' denied');
foreach(['jobId'=>'other','bootId'=>'old','state'=>'ABORTED','role'=>'source'] as $k=>$v)nativeOk(!$proof($j,array_replace($r,[$k=>$v]),$a,$q,'boot','completed','running'),'Changed authority '.$k.' denied');
nativeOk(!$proof($j,$r,$a,null,'boot','completed','running'),'No exact receiver process denied');nativeOk(!$proof($j,$r,$a,$q,'newboot','completed','running'),'Reboot invalidates native completion');
$pub='ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIEukcnZ5U8L3zZzI6BRsF39ZbYlWTCJ96Q6JH5HbXHaG native-test';$key=['publicKey'=>$pub,'keyId'=>unmManagedSshKeyId($pub),'expiresAt'=>1794570000,'sourceAddress'=>'192.0.2.1'];$line=unmLiveTrustedKeyLine($j,$key);
foreach(['restrict,port-forwarding,permitlisten="unmotion.invalid:65535"','from="192.0.2.1"','expiry-time="','command="/usr/local/sbin/unmotion-live-native-gate '.$j['id'],'unmotion-native:'.$j['id']] as $value)nativeOk(str_contains($line,$value),'Native key boundary '.$value);
nativeOk(!str_contains($line,'permitopen='),'Do not pretend OpenSSH can express a per-key Unix-path allowlist');
foreach(['','*','192.0.2.1,10.0.0.1','192.0.2.1" command="sh'] as $bad)nativeDeny(fn()=>unmLiveTrustedKeyLine($j,array_replace($key,['sourceAddress'=>$bad])),'Untrusted address denied');
$old="# user key remains byte exact\n";$added=unmLiveDataKeyRewrite($old,$j,$key,true);nativeOk(unmLiveDataKeyRewrite($added,$j,$key,false)===$old,'Exact native key removal preserves other authorisations');
$binding=$j+['sourceHostId'=>'source','destinationHostId'=>'target','persistentXml'=>'<domain><name>Squid Proxy</name><devices/></domain>','disks'=>$j['spec']['disks']];
$receipt=['schemaVersion'=>1,'jobId'=>$j['id'],'vmUuid'=>$j['vmUuid'],'bootId'=>'boot','contract'=>unmLiveCompletionContract($binding),'qemu'=>$q,'nativeHandoverVersion'=>1];
nativeOk(unmLiveCompletionCheck($receipt,$binding,'boot',$q),'Native receipt binds current contract');$legacy=$receipt;unset($legacy['nativeHandoverVersion']);nativeOk(!unmLiveCompletionCheck($legacy,$binding,'boot',$q),'Legacy receipt cannot authorise native handover');
nativeOk(!unmLiveCompletionCheck($receipt,array_replace($binding,['sshCipher'=>'aes256-gcm@openssh.com']),'boot',$q),'Pinned cipher mutation changes native contract');
nativeOk(unmLiveTrustedRollbackPossible(['state'=>'running','qemuAbsent'=>false,'qemu'=>$q+['extra'=>'full snapshot']],$q,$q),'Rollback compares like-for-like fresh compact identities, not the full status snapshot');
foreach([['state'=>'shut off','qemuAbsent'=>true],['state'=>'paused','qemuAbsent'=>false],['state'=>'undefined','qemuAbsent'=>true]] as $state)nativeOk(!unmLiveTrustedRollbackPossible($state,$q,$q),'Ambiguous or absent source never grants native rollback');
nativeOk(!unmLiveTrustedRollbackPossible(['state'=>'running','qemuAbsent'=>false],array_replace($q,['startTicks'=>'124']),$q),'A different source lifetime cannot roll back');
$binding['sourceBootId']='source-boot';$binding['destinationBootId']='destination-boot';
$success=['schemaVersion'=>1,'jobId'=>$binding['id'],'vmUuid'=>$binding['vmUuid'],'sourceHostId'=>$binding['sourceHostId'],'destinationHostId'=>$binding['destinationHostId'],'sourceBootId'=>'source-boot','destinationBootId'=>'destination-boot','contract'=>unmLiveCompletionContract($binding),'evidence'=>'native-cli-exit-0'];
nativeOk(unmLiveTrustedSuccessCheck($success,$binding),'Durable native CLI success binds both hosts, boots, VM and mapped contract');
nativeOk(unmLiveTrustedSuccessCheck(array_replace($success,['evidence'=>'authenticated-receiver-completed']),$binding),'Fresh authenticated receiver completion may repair a lost source journal');
foreach(['schemaVersion'=>2,'jobId'=>'other','vmUuid'=>'other','sourceHostId'=>'other','destinationHostId'=>'other','sourceBootId'=>'old','destinationBootId'=>'old','contract'=>'other','evidence'=>'source-absent'] as $key=>$bad)nativeOk(!unmLiveTrustedSuccessCheck(array_replace($success,[$key=>$bad]),$binding),'Native success mutation denied: '.$key);
foreach(['sourceBootId'=>'','destinationBootId'=>'','sshCipher'=>'aes256-gcm@openssh.com'] as $key=>$bad)nativeOk(!unmLiveTrustedSuccessCheck($success,array_replace($binding,[$key=>$bad])),'Unknown or changed admitted contract denied: '.$key);
nativeOk(!unmLiveTrustedSuccessCheck([],$binding),'No durable success evidence cannot authorize a stopped receiver');
echo "Trusted native migration regressions passed: $checks checks.\n";
