<?php
declare(strict_types=1);
require __DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/live-rpc.php';
$checks=0;
function ok(bool $value,string $message): void {global $checks;if(!$value)throw new RuntimeException($message);$checks++;}
function bad(callable $f,string $message): void {try{$f();}catch(Throwable){ok(true,$message);return;}throw new RuntimeException($message);}
function xs(string $s): string {return pack('N',strlen($s)).$s.str_repeat("\0",(4-strlen($s)%4)%4);}
function frame(int $method,int $serial,string $payload='',int $type=0): string {return pack('N7',28+strlen($payload),0x20008086,1,$method,$type,$serial,0).$payload;}
function openGuard(?callable $complete=null): UnmLiveRpcGuard {global $job;$g=new UnmLiveRpcGuard($job,null,null,$complete);$g->request(frame(66,0));$g->response(frame(66,0,pack('N',0),1));$g->request(frame(60,1,pack('N',1)));$g->response(frame(60,1,pack('N',1),1));$g->request(frame(1,2,pack('N',0).pack('N',0)));$g->response(frame(1,2,'',1));return $g;}
function params(array $values): string {$out=pack('N',count($values));foreach($values as $key=>$value)$out.=xs($key).pack('N',7).xs($value);return $out;}
$xml='<domain type="kvm"><name>Squid Proxy</name><uuid>11111111-1111-4111-8111-000000000001</uuid><devices/></domain>';
$job=['vmUuid'=>'11111111-1111-4111-8111-000000000001','vmName'=>'Squid Proxy','runtime'=>'/run/unmotion/live/live-'.str_repeat('a',24),'diskTarget'=>'vda','destinationXml'=>$xml,'persistentXml'=>$xml];
$values=['destination_xml'=>$xml,'persistent_xml'=>$xml,'migrate_uri'=>'unix://'.$job['runtime'].'/memory.sock','disks_uri'=>'unix://'.$job['runtime'].'/disk.sock','migrate_disks'=>'vda'];
$payload=params($values).xs('').pack('N',105);
function finishReply(string $name,string $uuid,int $domainId=42):string{return xs($name).hex2bin(str_replace('-','',$uuid)).pack('N',$domainId).xs('');}
$finishReply=finishReply($job['vmName'],$job['vmUuid']);$completed=[];
$g=openGuard(static function($domain)use(&$completed){$completed[]=$domain;});$g->request(frame(303,3,$payload));$g->response(frame(303,3,'',1));$g->request(frame(306,4,$payload.pack('N',0)));$g->response(frame(306,4,$finishReply,1));ok(count($completed)===1&&$completed[0]['vmUuid']===$job['vmUuid'],'validated prepare/paused finish attests the exact domain');
bad(fn()=>$g->request(frame(303,5,$payload)),'no prepare replay');
$completed=[];$g=openGuard(static function($domain)use(&$completed){$completed[]=$domain;});$g->request(frame(303,3,$payload));$g->response(frame(303,3,'',1));$g->request(frame(306,4,$payload.pack('N',1)));$g->response(frame(306,4,$finishReply,1));ok($completed===[],'cancelled finish never attests completion');
foreach([finishReply('Foreign',$job['vmUuid']),finishReply($job['vmName'],'11111111-1111-4111-8111-000000000002'),finishReply($job['vmName'],$job['vmUuid'],4294967295),'',$finishReply.'trailing'] as $badReply){$completed=[];$g=openGuard(static function($domain)use(&$completed){$completed[]=$domain;});$g->request(frame(303,3,$payload));$g->response(frame(303,3,'',1));$g->request(frame(306,4,$payload.pack('N',0)));bad(fn()=>$g->response(frame(306,4,$badReply,1)),'foreign/inactive/truncated finish rejected');ok($completed===[],'invalid finish never attests completion');}
$completed=[];$g=openGuard(static function($domain)use(&$completed){$completed[]=$domain;});$g->request(frame(303,3,$payload));$g->response(frame(303,3,'',1));$g->request(frame(306,4,$payload.pack('N',0)));$failed=frame(306,4,'native error',1);$failed=substr_replace($failed,pack('N',1),24,4);$g->response($failed);ok($completed===[],'failed native finish never attests completion');
foreach([24,29,30,42,52,103,214,217,999] as $method)bad(fn()=>openGuard()->request(frame($method,3)),'general libvirt method denied '.$method);
foreach([1,4457,105|2048,105|65536,105|16,105|8192] as $flags)bad(fn()=>openGuard()->request(frame(303,3,params($values).xs('').pack('N',$flags))),'unsafe/different migration flags denied');
foreach(['destination_xml'=>str_replace('Squid Proxy','Other VM',$xml),'persistent_xml'=>str_replace('devices','hostdev',$xml),'migrate_uri'=>'tcp://127.0.0.1:22','disks_uri'=>'unix:///var/run/libvirt/libvirt-sock','migrate_disks'=>'vdb','qemu_command'=>'anything'] as $key=>$value)bad(fn()=>openGuard()->request(frame(303,3,params(array_replace($values,[$key=>$value])).xs('').pack('N',105))),'foreign XML/path/option denied '.$key);
bad(fn()=>openGuard()->request(frame(303,3,$payload.'junk')),'trailing bytes denied');
bad(fn()=>openGuard()->request(frame(306,3,$payload.pack('N',0))),'finish before prepare denied');
bad(fn()=>openGuard()->request(frame(303,3,pack('N',33))),'parameter count bounded');
bad(fn()=>(new UnmLiveXdr(pack('N',4294967295)))->bytes(),'oversized XDR value denied');
bad(fn()=>(new UnmLiveXdr(pack('N',1).'a' . "\1\0\0"))->bytes(),'nonzero padding denied');
bad(fn()=>(new UnmLiveXdr(pack('N',2)))->optional(),'nonboolean XDR option denied');
bad(fn()=>openGuard()->response(frame(303,777,'',1)),'unsolicited response denied');
bad(fn()=>unmLiveXmlHash('<!DOCTYPE domain [<!ENTITY x SYSTEM "file:///etc/passwd">]><domain/>'),'entities denied');
$heartbeat=pack('N7',28,0x6b656570,1,1,2,0,0);$g=openGuard();$g->request($heartbeat);$g->response(pack('N7',28,0x6b656570,1,2,2,0,0));ok(true,'exact payload-free ping/pong allowed');
bad(fn()=>$g->response($heartbeat.'payload'),'heartbeat payload denied');bad(fn()=>$g->response(pack('N7',28,0x6b656570,1,3,2,0,0)),'unknown heartbeat denied');bad(fn()=>$g->request(pack('N7',28,0x6b656570,1,1,2,1,0)),'heartbeat serial denied');
$job['disks']=[['path'=>'/mnt/cache/Squid Proxy/a.raw','format'=>'raw','target'=>'vda'],['path'=>'/mnt/cache/Squid Proxy/b.qcow2','format'=>'qcow2','target'=>'vdb']];
function multiParams(array $disks): string {global $values;$base=$values;unset($base['migrate_disks']);$out=substr(params($base),4);foreach($disks as $target)$out.=xs('migrate_disks').pack('N',7).xs($target);return pack('N',count($base)+count($disks)).$out;}
$g=openGuard();$g->request(frame(303,3,multiParams(['vda','vdb']).xs('').pack('N',105)));ok(true,'every admitted disk may occur once in repeated typed parameters');
foreach([['vda'],['vdb'],['vda','vda'],['vda','vdc'],['vda','vdb','vdc']] as $targets)bad(fn()=>openGuard()->request(frame(303,3,multiParams($targets).xs('').pack('N',105))),'missing/duplicated/foreign disk cannot cross RPC boundary');
echo "Live RPC regressions passed: $checks checks.\n";
