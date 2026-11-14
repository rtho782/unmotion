<?php
// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
declare(strict_types=1);
namespace OrdinaryFixture;
require __DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/lib.php';
$code=file_get_contents(__DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/live-ordinary.php');
// Substitute only the persistence/host boundaries; execute the shipped state-machine functions.
$code=str_replace('function unmLiveRecordReplace(','function unusedRealRecordReplace(',$code);
eval('namespace OrdinaryFixture; use \\Throwable; use \\RuntimeException; use \\DOMXPath;'.substr($code,strpos($code,'function unmLiveOrdinaryOwner')));
$base=sys_get_temp_dir().'/unmotion-ordinary-'.bin2hex(random_bytes(8));mkdir($base,0700);
define(__NAMESPACE__.'\UNM_BOOT_DIR',$base);define(__NAMESPACE__.'\UNM_JOBS_DIR',$base.'/jobs');
$checks=0;$writes=[];$lost=false;$capsVersion=1;$local='11111111-1111-4111-8111-000000000001';$remote='22222222-2222-4222-8222-222222222222';$uuid='33333333-3333-4333-8333-333333333333';$live='live-'.str_repeat('a',24);$transfer='job-20261109-ordinary';$nonce=str_repeat('b',32);
$normalCert=null;$normalCapability=1;$normalProof=false;
$xml='<domain><name>Squid Proxy</name><uuid>'.$uuid.'</uuid><devices><disk type="file" device="disk"><source file="/mnt/cache/domains/Squid Proxy/disk.qcow2"/></disk></devices></domain>';
$record=['vmUuid'=>$uuid,'vmName'=>'Squid Proxy','state'=>'OWNER','role'=>'destination','diskPath'=>'/mnt/cache/domains/Squid Proxy/disk.qcow2','jobId'=>$live,'sourceHostId'=>$remote,'destinationHostId'=>$local];
$job=['id'=>$live,'vmUuid'=>$uuid,'vmName'=>'Squid Proxy','state'=>'COMPLETE','role'=>'destination'];$ordinary=['id'=>$transfer,'vmUuid'=>$uuid,'vmName'=>'Squid Proxy','peerId'=>'peer-lab'];
$peer=['id'=>'peer-lab','hostId'=>$remote];$vm=['state'=>'shut off','qemuAbsent'=>true];$remoteVm=['authority'=>'RETIRED','vm'=>['state'=>'undefined','qemuAbsent'=>true]];$rpc=[];$reservation=[];$run=[];$invalidated=false;
function ok(bool $value,string $why):void{global $checks;$checks++;if(!$value)throw new \RuntimeException($why);}
function deny(callable $f,string $why):void{try{$f();}catch(\Throwable){ok(true,$why);return;}throw new \RuntimeException('Expected denial: '.$why);}
function unmLiveJob($id){global $job;return $job;}
function unmLiveNativeRecord($base,$uuid){global $record;return $record;}
function unmLiveRecordReplace($next,$expected){global $record,$writes;ok($record===$expected,'Atomic authority compare-and-replace');$next['updatedAt']='persisted-'.count($writes);$record=$next;$writes[]=$next;return $next;}
function unmLivePeerForJob($j){global $peer;return $peer;}
function unmPeer($id){global $peer;return $peer;}
function unmLiveFenceProof(){}
function unmLiveVmState($uuid){global $vm;return $vm;}
function unmLiveVmLock($uuid){return fopen('php://temp','r+');}
function unmLiveCommand($args,$stdin=null,$seconds=20){global $xml;return $xml;}
function unmNativeDomain($xml){return \unmNativeDomain($xml);}
function unmLiveNativeBinding($d,$r){\unmLiveNativeBinding($d,$r);}
function unmLiveXmlHash($xml){return \unmLiveXmlHash($xml);}
function unmCpuConfigHash($xml){return \unmCpuConfigHash($xml);}
function unmActiveVmJob($uuid,$jobs){return null;}
function unmJobs(){return [];}
function unmNativeRecordForVm($base,$uuid){return null;}
function unmInventoryCache(){return new class{function invalidate(){global $invalidated;$invalidated=true;}};}
function unmHostId(){global $local;return $local;}
function unmNativeBoot(){return '44444444-4444-4444-8444-444444444444';}
function unmLivePrivateDir($dir){if(!is_dir($dir))mkdir($dir,0700,true);}
function unmAtomicJson($path,$value){global $ordinary;$ordinary=$value;}
function unmLoadJson($path){global $ordinary,$reservation;return str_contains($path,'/reservation/')?$reservation:$ordinary;}
function unmTransportId($id){return \unmTransportId($id);}
function unmTransportUuid($id){return \unmTransportUuid($id);}
function unmTransportReservationPath($peer,$id){return UNM_BOOT_DIR.'/reservation/'.$id;}
function unmTransportValidateDomainXml($xml,$uuid,$peer){ok(\unmXmlValue($xml,'uuid')===$uuid,'Receiver validates exact reserved XML identity');}
function unmXmlValue($xml,$tag){return \unmXmlValue($xml,$tag);}
function unmRun($args,$stdin,$seconds){global $run;$run[]=[$args,$stdin];return ['code'=>0,'stdout'=>'defined','stderr'=>''];}
function unmRemote($peer,$command,$seconds){global $capsVersion,$normalCapability;return ['code'=>0,'stdout'=>json_encode(['hostId'=>$peer['hostId'],'liveOrdinaryVersion'=>$capsVersion,'liveNormalVersion'=>$normalCapability]),'stderr'=>''];}
function unmLiveNormalCurrent($uuid){global $normalCert;return $normalCert;}
function unmLiveNormalRequirePeer($peer){global $normalCapability;if($normalCapability!==1)throw new \RuntimeException('Old peer');}
function unmLiveNormalRelease($a,$kind,$id,$peer,$proof){global $record,$normalCert;ok($record===$a&&$a['state']==='OWNER','Release only exact committed owner');$normalCert=['kind'=>$kind,'jobId'=>$id,'peerHostId'=>$peer,'authority'=>$a];$record=null;return $normalCert;}
function unmLiveRpc($peer,$action,$args,$seconds=30){
 global $remoteVm,$record,$lost,$rpc,$xml,$normalProof;$rpc[]=$action;
 if($action==='status')return $remoteVm;
 if($action==='handback-ack'){ok(($record['workflow']??'')==='ordinary-pending','Owner fenced before callback');if($lost)throw new \RuntimeException('Lost reply');return ['retired'=>true,'nonce'=>$args['nonce']];}
 if(in_array($action,['ordinary-accept','ordinary-commit'],true)){ok($record['state']==='RETIRED','Source retired before destination authority request');return ['success'=>true,'nonce'=>$args['nonce']];}
 if($action==='ordinary-proof')return ['retired'=>true,'nonce'=>$args['nonce'],'xmlHash'=>\unmLiveXmlHash($xml),'normalReturn'=>$normalProof];
 throw new \RuntimeException('Unexpected RPC '.$action);
}
try{
 $review=unmLiveHandbackReview($live);$vm=['state'=>'running','qemuAbsent'=>false];deny(fn()=>unmLiveHandback($live,$review['review']),'Running hand-back rejected');$vm=['state'=>'shut off','qemuAbsent'=>true];
 $old=$xml;$xml=str_replace('Squid Proxy','Changed Squid Proxy',$xml);deny(fn()=>unmLiveHandback($live,$review['review']),'Stale review cannot overwrite changed VM');$xml=$old;
 $lost=true;deny(fn()=>unmLiveHandback($live,$review['review']),'Lost acknowledgement stays fenced');ok($record['workflow']==='ordinary-pending'&&$record['state']==='OWNER','Pending journal survives the lost reply');
 $lost=false;$retry=unmLiveHandbackReview($live);unmLiveHandback($live,$retry['review']);ok(unmLiveOrdinaryOwner($record)&&$invalidated,'Explicit retry finishes ownership hand-back and invalidates display');
 $dir=UNM_JOBS_DIR.'/'.$transfer;mkdir($dir,0700,true);file_put_contents($dir.'/job.json','{}');file_put_contents($dir.'/destination.xml',$xml);
 $savedOrdinary=$ordinary;unset($ordinary['vmUuid']);$ordinary['request']=['VM_UUID'=>$uuid];ok(unmLiveOrdinaryJob($transfer)['vmUuid']===$uuid,'Released job request UUID remains resumable without rewriting historical jobs');$ordinary=$savedOrdinary;
 $ordinary['request']=['VM_UUID'=>$remote];deny(fn()=>unmLiveOrdinaryJob($transfer),'Conflicting top-level/request UUIDs are rejected');$ordinary=$savedOrdinary;
 $capsVersion=0;deny(fn()=>unmLiveOrdinaryWorker($transfer,'check'),'Old peer rejected before source shutdown/transfer');$capsVersion=1;
 unmLiveOrdinaryWorker($transfer,'prepare');ok($record['state']==='MOVING'&&$record['role']==='source'&&$record['sourceHostId']===$local,'Ordinary cutover establishes a source-role native fence');
 unmLiveOrdinaryWorker($transfer,'rollback');ok($record['state']==='OWNER','Rollback allowed only before retirement');
 unmLiveOrdinaryWorker($transfer,'prepare');unmLiveOrdinaryWorker($transfer,'accept');$retired=$record;ok($record['state']==='RETIRED','Source retirement durable before accept');
 unmLiveOrdinaryWorker($transfer,'rollback');ok($record===$retired,'Rollback never restores a retired source');
 ok(unmLiveOrdinaryResolutionCheck($uuid,$transfer),'The exact retired source may finish its original policy');
 deny(fn()=>unmLiveOrdinaryResolutionCheck($uuid,'other-job'),'An unrelated job cannot use the resolution exception');
 $proof=['action'=>'ordinary-proof','jobId'=>$transfer,'vmUuid'=>$uuid,'nonce'=>$record['transferNonce']];
 ok(unmLiveOrdinaryRemote($peer,$proof)['retired'],'Exact retired source proof');deny(fn()=>unmLiveOrdinaryRemote(['hostId'=>$local],$proof),'Other paired host cannot obtain source proof');deny(fn()=>unmLiveOrdinaryRemote($peer,array_replace($proof,['nonce'=>str_repeat('c',32)])),'Nonce replay rejected');
 $nonce=$record['transferNonce'];$record=null;$vm=['state'=>'undefined','qemuAbsent'=>true];$reservation=['id'=>$transfer,'vmUuid'=>$uuid,'sourceHostId'=>$remote];$stage=UNM_BOOT_DIR.'/incoming/'.$transfer;mkdir($stage,0700,true);file_put_contents($stage.'/destination.xml',$xml);
 $a=['action'=>'ordinary-accept','jobId'=>$transfer,'vmUuid'=>$uuid,'nonce'=>$nonce];unmLiveOrdinaryRemote($peer,$a);ok($record['state']==='RECEIVING','Receiver remains fenced before definition');
 deny(fn()=>unmLiveOrdinaryRemote($peer,array_replace($a,['action'=>'ordinary-commit'])),'Undefined receiver cannot become owner');
 unmLiveOrdinaryDefine($peer,$reservation,$xml);ok($run[0][0]===['virsh','define','/dev/stdin','--validate']&&$run[0][1]===$xml,'Only exact validated bytes reach libvirt via stdin');$vm=['state'=>'shut off','qemuAbsent'=>true];
 $old=$xml;$xml=str_replace('Squid Proxy','Tampered VM',$xml);deny(fn()=>unmLiveOrdinaryRemote($peer,array_replace($a,['action'=>'ordinary-commit'])),'Changed definition cannot receive authority');$xml=$old;
 unmLiveOrdinaryRemote($peer,array_replace($a,['action'=>'ordinary-commit']));ok(unmLiveOrdinaryOwner($record),'Only defined unchanged receiver gets ownership');
 $xml=str_replace('Squid Proxy','Changed after commit',$xml);deny(fn()=>unmLiveOrdinaryRemote($peer,$a),'Retry cannot overwrite a changed committed owner');$xml=$old;
 $normalCert=['authority'=>$record];$record=null;$normalCapability=0;deny(fn()=>unmLiveOrdinaryWorker($transfer,'check'),'Normal return fails preflight with an older peer');$normalCapability=1;
 unmLiveOrdinaryWorker($transfer,'check');ok($record===null,'Normal owner checks do not install an enduring fence');
 unmLiveOrdinaryWorker($transfer,'prepare');ok($record['state']==='MOVING'&&$record['normalReturn']===1,'Normal cutover creates only a temporary source handover');
 unmLiveOrdinaryWorker($transfer,'rollback');ok($record===null&&$normalCert['kind']==='ordinary','Pre-retirement rollback restores a normal source without hand-back');
 unmLiveOrdinaryWorker($transfer,'prepare');unmLiveOrdinaryWorker($transfer,'accept');$normalRetired=$record;ok($record['state']==='RETIRED','Normal source retired before receiver authority');
 $nonce=$record['transferNonce'];$normalCert=null;$record=$normalRetired;unset($record['workflow']);$normalProof=true;$vm=['state'=>'undefined','qemuAbsent'=>true];$a=['action'=>'ordinary-accept','jobId'=>$transfer,'vmUuid'=>$uuid,'nonce'=>$nonce];
 unmLiveOrdinaryRemote($peer,$a);ok($record['state']==='RECEIVING'&&$record['normalReturn']===1,'Fresh normal transfer can return to a previously fenced host');
 unmLiveOrdinaryDefine($peer,$reservation,$xml);$vm=['state'=>'shut off','qemuAbsent'=>true];unmLiveOrdinaryRemote($peer,array_replace($a,['action'=>'ordinary-commit']));
 ok($record===null&&$normalCert['kind']==='ordinary','Ordinary receiver is normal immediately at commit');
 $vm=['state'=>'running','qemuAbsent'=>false];$xml=str_replace('Squid Proxy','Renamed normal VM',$xml);ok(unmLiveOrdinaryRemote($peer,$a)['committed']===true,'Lost acknowledgement retry does not overwrite or stop an edited/running normal owner');
 $resume=substr($code,strpos($code,'function unmLiveOrdinaryResume'));
 ok(strpos($resume,"unmLiveOrdinaryWorker(\$id,'commit');")>strpos($resume,"if(empty(\$reply['committed']))"),'Interrupted persisted OWNER still passes idempotent commit to finish normal release');
 ok(str_contains($resume,"}\n    // OWNER")&&str_contains($resume,"\n    unmLiveOrdinaryWorker(\$id,'commit');"),'Reconciliation commit is outside optional definition branch');
 echo "Ordinary hand-back, stale reviews, lost replies, source fencing, mixed peers, nonce/peer binding and immutable receiver tests passed ($checks).\n";
}finally{
 foreach([$dir.'/job.json',$dir.'/destination.xml',$stage.'/destination.xml'] as $p)if(is_file($p))unlink($p);
 foreach([$dir,dirname($dir),$stage,dirname($stage),UNM_BOOT_DIR.'/live-authority',UNM_BOOT_DIR.'/live-managed',UNM_BOOT_DIR] as $p)if(is_dir($p))rmdir($p);
}
