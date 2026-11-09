<?php
declare(strict_types=1);
require __DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/native-fence.php';
$checks=0;
function check(bool $v,string $m): void {global $checks;if(!$v)throw new RuntimeException($m);$checks++;}
function deny(callable $f,string $m): void {try{$f();}catch(Throwable){check(true,$m);return;}throw new RuntimeException($m);}
$uuid='11111111-1111-4111-8111-000000000001';$id='live-'.str_repeat('a',24);$boot='test-boot';$now=100;
$xml='<domain><name>Squid Proxy</name><uuid>'.$uuid.'</uuid><devices><disk type="file" device="disk"><source file="/mnt/cache/domains/Squid Proxy/vdisk.qcow2"/></disk></devices></domain>';$d=unmNativeDomain($xml);
$r=['vmName'=>'Squid Proxy','vmUuid'=>$uuid,'diskPath'=>'/mnt/cache/domains/Squid Proxy/vdisk.qcow2','jobId'=>$id,'state'=>'RECEIVING','role'=>'destination','bootId'=>$boot,'expiresAt'=>200];
$call=fn($op,$a=null,$q=null,$record=null,$domain=null)=>unmLiveNativeDecision($record??$r,$domain??$d,$op,'begin',$a,$boot,$now,$q);
deny(fn()=>$call('prepare'),'direct receive start denied');
$a=$call('migrate');check($a['phase']==='INCOMING','incoming permit issued');
deny(fn()=>$call('migrate',$a),'incoming replay denied');
foreach(['bootId'=>'different','expiresAt'=>99,'state'=>'RETIRED','role'=>'source'] as $key=>$value)deny(fn()=>$call('migrate',null,null,array_replace($r,[$key=>$value])),'incoming mismatched '.$key);
$a=$call('prepare',$a);check($a['phase']==='PREPARED','incoming prepare admitted');deny(fn()=>$call('prepare',$a),'prepare replay denied');
$a=$call('start',$a);check($a['phase']==='STARTING','start consumes preparation');deny(fn()=>$call('start',$a),'start replay denied');
$q=['pid'=>99,'startTicks'=>'123','bootId'=>$boot,'uuid'=>$uuid];$a=$call('started',$a,$q);check($a['qemu']===$q,'exact QEMU recorded');
check($call('reconnect',$a,$q)===$a,'exact reconnect admitted');deny(fn()=>$call('reconnect',$a,array_replace($q,['startTicks'=>'456'])),'PID reuse reconnect denied');
deny(fn()=>$call('reconnect',null,$q),'missing reconnect proof denied');
foreach(['MOVING','RETIRED','ABORTED'] as $state)deny(fn()=>$call('prepare',null,null,array_replace($r,['state'=>$state])),'fenced state '.$state);
$owner=array_replace($r,['state'=>'OWNER']);check($call('prepare',null,null,$owner)['phase']==='PREPARED','committed owner may start');
deny(fn()=>$call('prepare',null,null,$owner,unmNativeDomain(str_replace('vdisk.qcow2','foreign.qcow2',$xml))),'changed disk denied');
deny(fn()=>$call('restore',null,null,$owner),'restore bypass denied');deny(fn()=>$call('attach',null,null,$owner),'attach bypass denied');
check($call('release',$a)===null,'release clears runtime not durable authority');
$efi=str_replace('<devices>','<os><loader readonly="yes" type="pflash">/usr/share/qemu/ovmf-x64/OVMF_CODE-pure-efi.fd</loader><nvram>/etc/libvirt/qemu/nvram/'.$uuid.'_VARS.fd</nvram></os><devices>',$xml);
$ed=unmNativeDomain($efi);$er=$owner+['firmware'=>unmLiveFirmwareXml($ed['xpath'],$uuid)];
check($call('prepare',null,null,$er,$ed)['phase']==='PREPARED','UEFI native start requires exact firmware binding');
deny(fn()=>$call('prepare',null,null,$owner,$ed),'BIOS owner cannot acquire UEFI implicitly');
deny(fn()=>$call('prepare',null,null,$er,unmNativeDomain(str_replace('_VARS.fd','_foreign.fd',$efi))),'changed NVRAM path remains fenced');
$ordinary=$owner+['workflow'=>'ordinary'];
$changed=unmNativeDomain(str_replace('Squid Proxy','Renamed Squid Proxy',str_replace('vdisk.qcow2','different.qcow2',$efi)));
check($call('prepare',null,null,$ordinary,$changed)['phase']==='PREPARED','Reviewed ordinary owner permits native hardware/CPU/rename edits with the same UUID');
foreach(['RETIRED','MOVING','ABORTED'] as $s)deny(fn()=>$call('prepare',null,null,array_replace($ordinary,['state'=>$s]),$changed),'Ordinary non-owner remains fenced despite changed disk/name/firmware: '.$s);
deny(fn()=>$call('migrate',null,null,array_replace($ordinary,['state'=>'RECEIVING'])),'Ordinary receiver cannot consume a live incoming permit');
deny(fn()=>$call('prepare',null,null,$owner+['workflow'=>'ordinary-pending']),'Pending reviewed hand-back fences native starts');
deny(fn()=>$call('prepare',null,null,$ordinary,unmNativeDomain(str_replace($uuid,'22222222-2222-4222-8222-222222222222',$xml))),'Ordinary guard remains bound to exact UUID');
echo "Live native fencing regressions passed: $checks checks.\n";
