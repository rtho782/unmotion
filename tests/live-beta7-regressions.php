<?php
// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
declare(strict_types=1);
require __DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/lib.php';
$checks=0;
function ok7(bool $v,string $m): void {global $checks;if(!$v)throw new RuntimeException($m);$checks++;}
function bad7(callable $f,string $m): void {try{$f();}catch(Throwable){ok7(true,$m);return;}throw new RuntimeException($m);}
$uuid='11111111-1111-4111-8111-111111111111';$id='live-'.str_repeat('a',24);
$disk='<disk type="file" device="disk"><driver name="qemu" type="qcow2" cache="none"/><source file="/mnt/cache/Squid Proxy/a.qcow2"/><target dev="vda" bus="virtio"/></disk>';
$second=str_replace(['a.qcow2','qcow2','vda'],['b.raw','raw','vdb'],$disk);
$empty='<disk type="file" device="cdrom"><driver type="raw"/><target dev="sda" bus="sata"/><readonly/></disk>';
$xml='<domain type="kvm"><name>Squid Proxy</name><uuid>'.$uuid.'</uuid><memory unit="KiB">2097152</memory><vcpu>2</vcpu><os><type arch="x86_64" machine="pc-q35-9.2">hvm</type></os><cpu mode="custom" match="exact"><model fallback="forbid">Skylake-Server-v5</model></cpu><devices>'.$disk.$second.$empty.'</devices></domain>';
$spec=unmLiveInspectXml($xml);ok7(count($spec['disks'])===2&&$spec['disks'][1]['format']==='raw','mixed independent disks, spaces and empty CD-ROM');
$runtimeEmpty=str_replace('<readonly/>','<source index="4" startupPolicy="optional"/><readonly/>',$xml);ok7(count(unmLiveInspectXml($runtimeEmpty)['disks'])===2,'libvirt runtime index does not make an empty CD-ROM attached media');
bad7(fn()=>unmLiveInspectXml(str_replace('<readonly/>','<source dev="/dev/sda"/><readonly/>',$xml)),'device-backed empty media rejected');
$mapped=unmLiveTargetXml($xml,['vda'=>'/mnt/zfspool/Squid Proxy/1.qcow2','vdb'=>'/mnt/zfspool/Squid Proxy/2.raw']);
ok7(array_column(unmLiveInspectXml($mapped)['disks'],'path')===['/mnt/zfspool/Squid Proxy/1.qcow2','/mnt/zfspool/Squid Proxy/2.raw'],'exact per-target mapping');
foreach([['vda'=>'/mnt/cache/a.qcow2'],['vda'=>'/mnt/cache/a.qcow2','vdb'=>'/mnt/cache/b.raw','vdc'=>'/mnt/cache/c.raw']] as $maps)bad7(fn()=>unmLiveTargetXml($xml,$maps),'no missing or additional mapping');
bad7(fn()=>unmLiveTargetXml($xml,'/mnt/cache/one.raw'),'legacy string mapping never maps multiple disks');
foreach(['vdb'=>'vda','b.raw'=>'a.qcow2','type="raw"'=>'type="vmdk"','/mnt/cache/Squid Proxy/b.raw'=>'/mnt/user/Squid Proxy/b.raw'] as $from=>$to)bad7(fn()=>unmLiveInspectXml(str_replace($from,$to,$xml)),'invalid additional disk '.$from);
bad7(fn()=>unmLiveInspectXml(str_replace('<readonly/>','<source file="/mnt/cache/install.iso"/><readonly/>',$xml)),'attached ISO remains blocked');
ok7(unmLiveDiskTotal([['bytes'=>12],['bytes'=>24]])===36,'aggregate capacity');bad7(fn()=>unmLiveDiskTotal([['bytes'=>PHP_INT_MAX],['bytes'=>1]]),'overflowing aggregate');
bad7(fn()=>unmLiveDisks(['disks'=>$spec['disks'],'diskPath'=>'/mnt/cache/other.raw']),'legacy/new aliases must agree');
$tpm='<tpm model="tpm-crb"><backend type="emulator" version="2.0" persistent_state="yes"><active_pcr_banks><sha256/></active_pcr_banks></backend></tpm>';
$withTpm=str_replace('</devices>',$tpm.'</devices>',$xml);$source=unmLiveInspectXml($withTpm);ok7($source['tpm']['model']==='tpm-crb','software TPM 2.0 accepted structurally');
$path=unmLiveTpmDestination($uuid,$id);$dest=unmLiveTargetXml($withTpm,array_column($spec['disks'],'path','target'),null,$path);
$destination=unmLiveInspectXml($dest);ok7($destination['tpm']['path']===$path&&str_contains($dest,'persistent_state="yes"'),'destination TPM has independent durable job/UUID path');
foreach(['version="2.0"'=>'version="1.2"','type="emulator"'=>'type="passthrough"','<sha256/>'=>'<sha256/><sha256/>','</backend>'=>'<encryption secret="11111111-1111-4111-8111-111111111111"/></backend>'] as $from=>$to)bad7(fn()=>unmLiveInspectXml(str_replace($from,$to,$withTpm)),'unsupported TPM contract '.$from);
ok7(unmLiveTpmPathAllowed($path,$uuid,true),'exact cycle path accepted');
foreach([str_replace($uuid,'22222222-2222-4222-8222-222222222222',$path),$path.'/../other',$path.'/child','/tmp/'.$uuid] as $bad)ok7(!unmLiveTpmPathAllowed($bad,$uuid),'foreign/traversal/nested TPM path rejected');
$tools=['version'=>'0.7.3','swtpmSha256'=>str_repeat('a',64),'libtpmsSha256'=>str_repeat('b',64)];unmLiveTpmCompatible($source['tpm'],$tools,$tools);ok7(true,'identical TPM implementation');bad7(fn()=>unmLiveTpmCompatible($source['tpm'],$tools,array_replace($tools,['version'=>'0.8.0'])),'mixed TPM implementation rejected');
$record=['vmUuid'=>$uuid,'vmName'=>'Squid Proxy','firmware'=>null,'diskPath'=>$destination['diskPath'],'disks'=>$destination['disks'],'tpm'=>$destination['tpm']];
unmLiveNativeBinding(unmNativeDomain($dest),$record);ok7(true,'native fence accepts every exact disk and TPM binding');
$pretty=str_replace(['<backend','<source type="dir"','</backend>'],["\n  <backend","\n    <source type=\"dir\"","\n  </backend>"],$dest);
unmLiveNativeBinding(unmNativeDomain($pretty),$record);ok7(true,'native hook indentation cannot change the exact TPM contract');
bad7(fn()=>unmLiveNativeBinding(unmNativeDomain(str_replace('b.raw','other.raw',$dest)),$record),'changed second disk rejected by native fence');
bad7(fn()=>unmLiveNativeBinding(unmNativeDomain(str_replace('tpm-crb','tpm-tis',$dest)),$record),'changed TPM rejected by native fence');
$blocked=str_replace(['mode="custom"','type="emulator"','<readonly/>'],['mode="host-passthrough"','type="external"','<source file="/mnt/cache/install.iso"/><readonly/>'],$withTpm);
$reasons=unmLiveXmlBlockers($blocked);ok7(count($reasons)>=3&&!array_diff(['cpu','tpm','storage'],array_column($reasons,'code')),'readiness lists independent CPU, TPM and storage blockers');
$extra=str_replace(['machine="pc-q35-9.2"','</devices>'],['machine="q35"','<graphics type="spice"/></devices>'],$blocked);$reasons=unmLiveXmlBlockers($extra);ok7(!array_diff(['cpu','tpm','storage','machine','display'],array_column($reasons,'code')),'machine and display blockers are not hidden by an earlier CPU or TPM failure');
ok7(unmReplicationPointInventoryEvidence(['points'=>[['id'=>'one'],['id'=>'two']]])['pointCount']===2,'source count comes from authenticated destination inventory');
ok7(unmReplicationPointInventoryEvidence(['manifest'=>['points'=>[['id'=>'one']]],'pointCount'=>1])['pointCount']===1,'post-prune manifest count');
bad7(fn()=>unmReplicationPointInventoryEvidence(['points'=>[['id'=>'one']],'pointCount'=>0]),'contradictory count rejected');bad7(fn()=>unmReplicationPointInventoryEvidence(['points'=>[['id'=>'one'],['id'=>'one']]]),'duplicate point identity rejected');
ok7(unmLiveDataLimit(['disks'=>$spec['disks']],'disk')===3&&unmLiveDataLimit([],'memory')===1,'NBD admission is bounded by exact disk count plus one probe');
bad7(fn()=>unmLiveDataLimit([],'control'),'no arbitrary data channel');
ok7(unmLiveDataWithinWindow(1000,1301),'small-disk NBD channel survives five-minute idle while other disks/RAM transfer');
ok7(unmLiveDataWithinWindow(1000,2800),'data channel lasts through the worker transfer limit');
ok7(unmLiveDataWithinWindow(1000,2920),'bounded rollback/finish grace remains available');
ok7(!unmLiveDataWithinWindow(1000,2921),'channel cannot outlive the total bounded transfer window');
ok7(!unmLiveDataWithinWindow(1000,999),'backwards clock cannot extend channel lifetime');
$pumpStreams=[fopen('php://temp','w+b'),fopen('php://temp','w+b'),fopen('php://temp','w+b')];
try{bad7(fn()=>unmLiveRawPump($pumpStreams[0],$pumpStreams[1],$pumpStreams[2],static fn()=>false),'revoked authority still terminates an idle data channel immediately');ok7(fstat($pumpStreams[1])['size']===0,'lease rejection writes no human-readable output into the binary stream');}
finally{foreach($pumpStreams as $stream)fclose($stream);}
$locks=sys_get_temp_dir().'/unmotion-beta7-data-'.bin2hex(random_bytes(8));mkdir($locks);$held=[];
try{for($i=0;$i<3;$i++){[$h,$slot]=unmLiveDataLock(['disks'=>$spec['disks']],$locks,'disk');$held[]=$h;ok7($slot==='disk-'.$i,'independent concurrent NBD slot');}bad7(fn()=>unmLiveDataLock(['disks'=>$spec['disks']],$locks,'disk'),'excess NBD connection refused');flock($held[1],LOCK_UN);fclose($held[1]);unset($held[1]);[$h,$slot]=unmLiveDataLock(['disks'=>$spec['disks']],$locks,'disk');$held[]=$h;ok7($slot==='disk-1','released NBD slot may be reused inside the same live lease');}
finally{foreach($held as $h){flock($h,LOCK_UN);fclose($h);}foreach(glob($locks.'/*')?:[] as $p)unlink($p);rmdir($locks);}
echo "Beta7 multi-disk, TPM and native-binding checks passed: $checks.\n";
