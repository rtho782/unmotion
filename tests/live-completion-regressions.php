<?php
// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
declare(strict_types=1);
require __DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/lib.php';
$checks=0;function completionOk(bool $v,string $m):void{global $checks;if(!$v)throw new RuntimeException($m);$checks++;}
$j=['id'=>'live-'.str_repeat('a',24),'vmUuid'=>'b9b90001-1111-4111-8111-000000000001','sourceHostId'=>'source','destinationHostId'=>'dest','persistentXml'=>'<domain><name>Squid Proxy</name><devices/></domain>','disks'=>[['path'=>'/mnt/cache/domains/Squid Proxy/disk.raw','target'=>'vda','format'=>'raw','bytes'=>1048576]]];$q=['pid'=>42,'bootId'=>'boot','startTicks'=>'123','executable'=>'/usr/bin/qemu-system-x86_64','argv'=>['qemu','-uuid',$j['vmUuid']]];
$r=['schemaVersion'=>1,'jobId'=>$j['id'],'vmUuid'=>$j['vmUuid'],'bootId'=>'boot','contract'=>unmLiveCompletionContract($j),'qemu'=>$q];completionOk(unmLiveCompletionCheck($r,$j,'boot',$q),'exact completed job and process accepted');
foreach(['schemaVersion'=>2,'jobId'=>'live-'.str_repeat('b',24),'vmUuid'=>'other','bootId'=>'old','contract'=>str_repeat('0',64)] as $k=>$v)completionOk(!unmLiveCompletionCheck(array_replace($r,[$k=>$v]),$j,'boot',$q),'changed completion '.$k.' denied');
completionOk(!unmLiveCompletionCheck([],$j,'boot',$q),'missing receipt is not completion');completionOk(!unmLiveCompletionCheck($r,$j,'new-boot',$q),'host reboot invalidates old in-memory receipt');completionOk(!unmLiveCompletionCheck($r,$j,'boot',null),'absent QEMU is not a paused completed receiver');
foreach(['pid'=>43,'startTicks'=>'124','bootId'=>'new','argv'=>['different'],'executable'=>'/usr/bin/other'] as $k=>$v)completionOk(!unmLiveCompletionCheck($r,$j,'boot',array_replace($q,[$k=>$v])),'changed QEMU lifetime '.$k.' denied');
$changed=$j;$changed['disks'][0]['path']='/mnt/cache/foreign.raw';completionOk(!unmLiveCompletionCheck($r,$changed,'boot',$q),'different admitted disk rejected');$changed=$j;$changed['persistentXml']='<domain><name>Other</name></domain>';completionOk(!unmLiveCompletionCheck($r,$changed,'boot',$q),'changed XML rejected');
$engine=file_get_contents(__DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/live-engine.php');$commit=substr($engine,strpos($engine,"if(\$action==='commit')"));completionOk(strpos($commit,"empty(\$status['transferComplete'])")<strpos($commit,"unmLiveAuthority(\$j,'OWNER'"),'legacy completion checked before ownership grant');completionOk(str_contains($engine,"\$state['qemuAbsent']&&(\$nativeSuccess||!empty(\$remote['transferComplete']))"),'reconciliation needs source absence AND durable native success or authenticated receiver completion, not paused state alone');
$native=file_get_contents(__DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/live-trusted-native.php');$commit=substr($native,strpos($native,'function unmLiveTrustedCommit'));
completionOk(strpos($commit,'unmLiveTrustedSuccessCheck')<strpos($commit,"unmLiveAuthority(\$j,'OWNER'"),'native commit validates durable success before a new ownership grant');
completionOk(!str_contains($commit,"['virsh','resume'"),'native bookkeeping never resumes an already handed-over guest');
echo "Native receive-completion boot/process/contract checks passed ($checks).\n";
