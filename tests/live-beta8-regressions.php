<?php
// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
declare(strict_types=1);
require __DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/lib.php';
$n=0;function ok8(bool $v,string $m): void {global $n;if(!$v)throw new RuntimeException($m);$n++;}function bad8(callable $f,string $m): void {try{$f();}catch(Throwable){ok8(true,$m);return;}throw new RuntimeException($m);}
$uuid='11111111-1111-4111-8111-111111111111';
$cap=['hostId'=>$uuid,'pluginVersion'=>UNM_VERSION,'liveMigrationVersion'=>4,'liveDataTransportVersion'=>1,'liveNormalVersion'=>1];unmLiveAssertVersion($cap,$uuid,true);ok8(true,'matching live contract accepted');
foreach([['pluginVersion'=>'0.5.0-beta7'],['liveMigrationVersion'=>3],['liveMigrationVersion'=>'4'],['hostId'=>'different']] as $change)bad8(fn()=>unmLiveAssertVersion(array_replace($cap,$change),$uuid,true),'mixed version/capability/identity rejected in preflight');
bad8(fn()=>unmLiveAssertVersion(['hostId'=>$uuid,'version'=>UNM_VERSION,'liveMigrationVersion'=>3],$uuid),'fresh platform cannot contradict advertised live capability');
$volume=['dataset'=>'cache/vm-zvols/Squid Proxy','guid'=>'18446744073709551615','volsize'=>67108864,'volblocksize'=>16384];
$z=['path'=>'/dev/zvol/cache/vm-zvols/Squid+Proxy','target'=>'vda','format'=>'raw','type'=>'zvol','bytes'=>67108864,'volume'=>$volume];
ok8(unmLiveDisks(['disks'=>[$z]])===[$z],'zvol string GUID and geometry retained');
foreach(['path'=>'/dev/sda','format'=>'qcow2','type'=>'block'] as $k=>$v)bad8(fn()=>unmLiveDiskIdentity(array_replace($z,[$k=>$v])),'unsafe zvol '.$k);
foreach(['/dev/zvol/cache/a/../b','/dev/zvol/cache/a//b','/dev/zvol/cache/a@snap','/dev/zvol/cache/a#bookmark',"/dev/zvol/cache/a\n"] as $p)bad8(fn()=>unmLiveDiskIdentity(array_replace($z,['path'=>$p])),'unsafe zvol path');
foreach(['guid'=>123,'volsize'=>1,'volblocksize'=>1000,'dataset'=>'cache/a/../b'] as $k=>$v)bad8(fn()=>unmLiveVolumeContract(array_replace($volume,[$k=>$v])),'invalid volume '.$k);
bad8(fn()=>unmLiveDisks(['disks'=>[array_replace($z,['bytes'=>1])]]),'geometry/capacity mismatch');
$disk='<disk type="block" device="disk"><driver name="qemu" type="raw" cache="none"/><source dev="'.$z['path'].'"/><target dev="vda" bus="virtio"/></disk>';
$xml='<domain type="kvm"><name>Squid Proxy</name><uuid>'.$uuid.'</uuid><memory unit="KiB">1048576</memory><vcpu>2</vcpu><os><type arch="x86_64" machine="pc-q35-9.2">hvm</type></os><cpu mode="custom" match="exact"><model fallback="forbid">Skylake-Server-v5</model></cpu><devices>'.$disk.'</devices></domain>';
ok8(unmLiveInspectXml($xml)['disks']===[unmLiveDiskIdentity($z)],'whole zvol accepted structurally');
ok8(unmLiveInspectXml(str_replace('dev="'.$z['path'].'"','dev="'.$z['path'].'" index="1" startupPolicy="optional"',$xml))['disks']===[unmLiveDiskIdentity($z)],'bounded libvirt source index/startup policy retained');
ok8(str_contains(unmLiveTargetXml($xml,['vda'=>$z['path']]),'Squid+Proxy'),'encoded source alias can be structurally reviewed with local capability');
$mapped=unmLiveTargetXml($xml,['vda'=>'/dev/zvol/zfspool/vm-zvols/unmotion-live-123-vdisk1']);ok8(str_contains($mapped,'source dev=')&&!str_contains($mapped,'source file='),'zvol destination remains block-backed');
bad8(fn()=>unmLiveTargetXml($xml,['vda'=>'/mnt/cache/a.raw']),'no implicit zvol-to-file conversion');
bad8(fn()=>unmLiveInspectXml(str_replace('/></disk>','/><readonly/></disk>',$xml)),'read-only zvol rejected');
bad8(fn()=>unmLiveInspectXml(str_replace('dev="'.$z['path'].'"','dev="'.$z['path'].'" file="/mnt/cache/a.raw"',$xml)),'ambiguous mixed source attributes rejected');
$r=['jobId'=>'live-'.str_repeat('a',24),'vmUuid'=>$uuid,'disks'=>[$z]];$r2=$r;$r2['disks'][0]['volume']['guid']='1';ok8(unmLiveZvolProofKey($r)!==unmLiveZvolProofKey($r2),'proof bound to original GUID');
$intent=['id'=>'live-'.str_repeat('b',24),'vmUuid'=>$uuid,'sourceHostId'=>'source','destinationHostId'=>'dest','diskPath'=>$z['path'],'bootId'=>'boot','priorAuthority'=>null];$fence=$intent;unset($fence['id'],$fence['priorAuthority']);$fence+=['jobId'=>$intent['id'],'state'=>'ABORTED','role'=>'destination'];
ok8(unmLiveProvisioningAbortable(null,$intent)&&unmLiveProvisioningAbortable($fence,$intent),'provisioning rollback covers before/after early non-startable fence');
foreach(['state'=>'RECEIVING','role'=>'source','jobId'=>'different','vmUuid'=>'different','diskPath'=>'/dev/zvol/cache/foreign'] as $k=>$v)ok8(!unmLiveProvisioningAbortable(array_replace($fence,[$k=>$v]),$intent),'no provisioning rollback of altered/granted authority '.$k);
bad8(fn()=>unmLiveZvolNativeCheck($r,'boot','/not-existing-unmotion-proof'),'missing local proof rejects start');
bad8(fn()=>unmLiveZvolKernel('/dev/null'),'character device is not zvol');
$absent=['state'=>'undefined','qemuAbsent'=>true];unmLiveUnreservedGuard(null,$absent,null);ok8(true,'unreserved target absence accepted without granting ownership');
foreach(['RETIRED','ABORTED'] as $state){unmLiveUnreservedGuard(['state'=>$state],$absent,null);ok8(true,'old non-owner does not strand unreserved rollback');}
foreach(['OWNER','MOVING','RECEIVING','unknown'] as $state)bad8(fn()=>unmLiveUnreservedGuard(['state'=>$state],$absent,null),'unreserved abort cannot replace '.$state);
foreach([['state'=>'shut off'],['state'=>'paused'],['state'=>'running'],['qemuAbsent'=>false],['qemuAbsent'=>1]] as $change)bad8(fn()=>unmLiveUnreservedGuard(null,array_replace($absent,$change),null),'unreserved abort requires exact undefined/absent proof');
bad8(fn()=>unmLiveUnreservedGuard(null,$absent,['managed'=>true]),'recovery ownership is not an unreserved live attempt');
$request=['jobId'=>'live-'.str_repeat('c',24),'vmUuid'=>$uuid,'token'=>str_repeat('a',64)];$peer=['hostId'=>'source-host'];$nonce=str_repeat('d',32);$proof=['hostId'=>'source-host','role'=>'source','jobId'=>$request['jobId'],'vmUuid'=>$uuid,'authority'=>'MOVING','nonce'=>$nonce,'tokenHash'=>hash('sha256',$request['token'])];unmLiveUnreservedProof($proof,$request,$peer,$nonce);ok8(true,'fresh callback binds unreserved abandonment to peer/job/VM/token/nonce');
foreach(['hostId'=>'foreign','role'=>'destination','jobId'=>'different','vmUuid'=>'different','authority'=>'RETIRED','nonce'=>'stale','tokenHash'=>str_repeat('0',64)] as $key=>$value)bad8(fn()=>unmLiveUnreservedProof(array_replace($proof,[$key=>$value]),$request,$peer,$nonce),'unreserved callback rejects changed '.$key);
$tmp=sys_get_temp_dir().'/unmotion-beta8-proof-'.bin2hex(random_bytes(8));
try{file_put_contents($tmp,json_encode(['bootId'=>'wrong','binding'=>unmLiveZvolProofKey($r),'devices'=>[]]));bad8(fn()=>unmLiveZvolNativeCheck($r,'current',$tmp),'prior-boot local proof rejected');file_put_contents($tmp,json_encode(['bootId'=>'current','binding'=>unmLiveZvolProofKey($r2),'devices'=>[]]));bad8(fn()=>unmLiveZvolNativeCheck($r,'current',$tmp),'other-GUID proof rejected before device inspection');file_put_contents($tmp,'{');bad8(fn()=>unmLiveZvolNativeCheck($r,'current',$tmp),'corrupt local proof rejected');}finally{if(is_file($tmp))unlink($tmp);}
$g=unmLiveCapacityBudget([['group'=>'zfs:cache','kind'=>'zvol','bytes'=>1073741824,'available'=>10737418240],['group'=>'zfs:cache','kind'=>'file','bytes'=>1073741824,'available'=>5368709120]]);
ok8(count($g)===1&&$g[0]['requiredBytes']===2281701376&&$g[0]['availableBytes']===5368709120&&$g[0]['sufficient'],'same pool sums files/zvols and honors smaller quota');
ok8(!unmLiveCapacityBudget([['group'=>'pool','kind'=>'zvol','bytes'=>1073741824,'available'=>1073741824]])[0]['sufficient'],'low capacity including headroom rejected');
bad8(fn()=>unmLiveCapacityBudget([['group'=>'pool','bytes'=>PHP_INT_MAX,'available'=>PHP_INT_MAX]]),'capacity arithmetic overflow');
ok8(unmLiveReservationEstimate("create\tcache/test\nproperty\trefreservation\t70254592\n",67108864)===70254592,'fresh ZFS dry-run reservation parsed');
foreach(["property\trefreservation\t0","property\trefreservation\tauto","property\trefreservation\t99999999999999999999999","property\trefreservation\t70254592\nproperty\trefreservation\t70254592",''] as $bad)bad8(fn()=>unmLiveReservationEstimate($bad,67108864),'unprovable or thin reservation rejected');
foreach(['disk_remaining: 1024\nmemory_remaining: 2048'=>'DISK_COPY','disk_remaining: 0\nmemory_remaining: 2048'=>'RAM_PRECOPY','disk_remaining: 0\nmemory_remaining: 2048\nmemory_iteration: 3'=>'RAM_CONVERGENCE','disk_remaining: 0\nmemory_remaining: 0'=>'FINISHING_COPY'] as $stats=>$phase)ok8(unmLiveProgressParse(str_replace('\\n',"\n",$stats))['phase']===$phase,'independent progress phase '.$phase);
ok8(unmLiveProgressParse("Job type: 0\n")===null,'no fake completion from no-job status');
ok8(unmLiveProgressParse("disk_remaining: 9999999999999999999999\n")===null,'overflow telemetry ignored');
ok8(unmLiveProgressParse("disk_remaining: 1\ndisk_remaining: 2\n")===null,'duplicate telemetry ignored');
$p=unmLiveProgressParse("disk_total: 1024\ndisk_processed: 4096\ndisk_remaining: 128\nmemory_remaining: 2048\n");ok8(!isset($p['percent'])&&$p['disk']['processedBytes']===4096,'retransmission cannot produce overall percent');
echo "Beta8 storage, capacity and progress checks passed: $n.\n";
