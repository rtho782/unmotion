<?php
// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
declare(strict_types=1);
namespace IncarnationFixture;
require __DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/lib.php';
$code=file_get_contents(__DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/replication-incarnation.php');
$code=str_replace('function unmReplicationPairPolicies(','function unusedRealPairPolicies(',$code);
eval('namespace IncarnationFixture; use \\RuntimeException;'.substr($code,strpos($code,'function ')));
$lib=file_get_contents(__DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/lib.php');
foreach(['unmReplicationPolicy'=>'unmReplicationDurableState','unmValidateReplicaRequestIdentity'=>'unmReplicaVmUuidUndefinedInInventory'] as $first=>$next){$start=strpos($lib,'function '.$first.'(');$end=strpos($lib,'function '.$next.'(',$start);eval('namespace IncarnationFixture; use \\RuntimeException; use \\InvalidArgumentException;'.substr($lib,$start,$end-$start));}
$source='11111111-1111-4111-8111-111111111111';$dest='22222222-2222-4222-8222-222222222222';$uuid='33333333-3333-4333-8333-333333333333';$policies=[];$files=[];$live=false;$idle=true;$checks=0;
function ok(bool $v,string $why):void{global $checks;++$checks;if(!$v)throw new \RuntimeException($why);}
function deny(callable $f,string $why):void{try{$f();}catch(\Throwable){ok(true,$why);return;}throw new \RuntimeException('Expected refusal: '.$why);}
function unmReplicationId($u,$d,$n=''){return \unmReplicationId($u,$d,$n);}
function unmReplicationPath($id){\unmReplicationPath($id);return '/fixture/replications/'.$id;}
function unmReplicationPairPolicies($u,$d){global $policies;return $policies;}
function unmCleanupRead($p){global $files;return $files[$p]??[];}
function unmLoadJson($p){return unmCleanupRead($p);}
function unmRetirementPath($id){return unmReplicationPath($id).'/retirement.json';}
function unmRecoverySourcePath($id){return unmReplicationPath($id).'/recovery.json';}
function unmReplicationStorageFingerprint($s){return \unmReplicationStorageFingerprint($s);}
function unmRetirementIdentity($p){global $source;return ['sourceHostId'=>$source,'destinationHostId'=>$p['peerHostId'],'replicationId'=>$p['id'],'vmUuid'=>$p['vmUuid'],'vmName'=>$p['vmName']]+(isset($p['incarnation'])?['incarnation'=>$p['incarnation']]:[]);}
function unmProcessRecordedWorker($p,$s,$a){global $live;return $live?['pid'=>42]:null;}
function unmRetirementAssertRecoveryIdle($p){global $idle;if(!$idle)throw new \RuntimeException('Unresolved authority');}
function unmLoadConfig(){return ['image_dir'=>'/mnt/backup/domains'];}
function unmReplicaIdentityPart($s,$label=''){return \unmReplicaIdentityPart($s,$label);}
function unmHostId(){global $dest;return $dest;}
function unmFindPeerByHostId($id){return ['pairingState'=>'paired'];}
function unmReplicaPath($s,$id){unmReplicationPath($id);return '/fixture/replicas/'.$s.'/'.$id;}
function fixture(string $state='ENDED_KEEP',string $nonce=''):array{
 global $source,$dest,$uuid,$policies,$files,$live,$idle;
 $live=false;$idle=true;$p=['id'=>unmReplicationId($uuid,$dest,$nonce),'vmUuid'=>$uuid,'peerHostId'=>$dest,'vmName'=>'Squid Proxy','storage'=>[['source'=>'pool/Squid Proxy','destination'=>'backup/old']]];if($nonce!=='')$p['incarnation']=$nonce;
 $r=unmRetirementIdentity($p)+['state'=>$state,'phase'=>'COMPLETE','mode'=>$state==='ENDED_DELETE'?'delete':'keep','token'=>str_repeat('a',32),'storageHash'=>unmReplicationStorageFingerprint($p['storage'])];
 $r['remoteAck']=['success'=>true,'token'=>$r['token'],'mode'=>$r['mode'],'storageHash'=>$r['storageHash'],'partialRetained'=>false];$policies=[$p];$files=[unmRetirementPath($p['id'])=>$r,unmReplicationPath($p['id']).'/policy.json'=>$p];return $p;
}
$legacy=unmReplicationId($uuid,$dest);$cycle=unmReplicationSelectCycle($uuid,$dest,true);ok($cycle===['id'=>$legacy],'First cycle preserves legacy protocol-7 identity');
foreach(['ENDED_KEEP','ENDED_DELETE'] as $state){$old=fixture($state);$new=unmReplicationSelectCycle($uuid,$dest,true);ok($new['id']!==$legacy&&preg_match('/^[a-f0-9]{32}$/',$new['incarnation'])===1,'Ended cycle gets a fresh nonce and id');ok($new['id']===unmReplicationId($uuid,$dest,$new['incarnation']),'Full VM/destination/nonce binding');ok(unmReplicationSelectCycle($uuid,$dest,true)['id']!==$new['id'],'Subsequent proposals never reuse a nonce');ok($policies===[$old]&&$files[unmReplicationPath($old['id']).'/policy.json']===$old,'History/tombstones remain unchanged');}
$p=fixture('ENDED_KEEP',str_repeat('b',32));ok(unmReplicationSelectCycle($uuid,$dest,true)['id']!==$p['id'],'Further incarnations also restart independently');
$p=fixture();unset($files[unmRetirementPath($p['id'])]);ok(unmReplicationSelectCycle($uuid,$dest)['id']===$p['id'],'Preview edits the current active cycle');deny(fn()=>unmReplicationSelectCycle($uuid,$dest,true),'Duplicate creation cannot replace an active cycle');$policies[]=$p;deny(fn()=>unmReplicationSelectCycle($uuid,$dest),'Multiple active records fail closed');
foreach(['ENDING','BLOCKED'] as $state){fixture($state);deny(fn()=>unmReplicationSelectCycle($uuid,$dest,true),'Unfinished retirement blocks recreation');}
$p=fixture('BLOCKED');$current=$p;$current['incarnation']=str_repeat('9',32);$current['id']=unmReplicationId($uuid,$dest,$current['incarnation']);$policies[]=$current;ok(unmReplicationSelectCycle($uuid,$dest)['id']===$current['id'],'An older cleanup blocker does not hijack current-cycle configuration');deny(fn()=>unmReplicationSelectCycle($uuid,$dest,true),'An active current cycle still prevents another creation');
foreach(['remoteAck','storageHash','phase'] as $field){$p=fixture();unset($files[unmRetirementPath($p['id'])][$field]);deny(fn()=>unmReplicationSelectCycle($uuid,$dest,true),'Missing terminal proof blocks recreation');}
$p=fixture();$files[unmRetirementPath($p['id'])]['remoteAck']['token']='wrong';deny(fn()=>unmReplicationSelectCycle($uuid,$dest,true),'Mismatched ack blocks recreation');
fixture();$live=true;deny(fn()=>unmReplicationSelectCycle($uuid,$dest,true),'Finishing worker blocks recreation');fixture();$idle=false;deny(fn()=>unmReplicationSelectCycle($uuid,$dest,true),'Unresolved recovery blocks recreation');
$p=fixture('ENDED_DELETE',str_repeat('c',32));ok(unmReplicationPolicy($p['id'])===$p,'Persisted new policy validates');$files[unmReplicationPath($p['id']).'/policy.json']['incarnation']=str_repeat('d',32);deny(fn()=>unmReplicationPolicy($p['id']),'Changed nonce invalidates policy identity');
foreach([null,[],true,'',str_repeat('A',32),'short'] as $bad)deny(fn()=>unmReplicationCycleNonce(['incarnation'=>$bad]),'Malformed explicit nonce fails closed');
$old=fixture();ok(unmReplicationPolicy($old['id'])===$old,'Persisted legacy policy remains valid');
$nonce=str_repeat('e',32);$q=['sourceHostId'=>$source,'destinationHostId'=>$dest,'vmUuid'=>$uuid,'vmName'=>'Squid Proxy','replicationId'=>unmReplicationId($uuid,$dest,$nonce),'incarnation'=>$nonce];$identity=unmValidateReplicaRequestIdentity($q);ok($identity==$q,'New destination admission validates explicit full nonce binding');
deny(fn()=>unmValidateReplicaRequestIdentity(array_replace($q,['incarnation'=>str_repeat('f',32)])),'Wrong nonce cannot authorize another cycle');
$without=$q;unset($without['incarnation']);deny(fn()=>unmValidateReplicaRequestIdentity($without),'Unreserved new identity needs its nonce');$files[unmReplicaPath($source,$q['replicationId']).'/manifest.json']=$q;ok(unmValidateReplicaRequestIdentity($without)==$q,'Existing RPCs resolve only the exact reserved incarnation');
$other=$q;$other['vmUuid']='44444444-4444-4444-8444-444444444444';deny(fn()=>unmValidateReplicaRequestIdentity($other),'Nonce never permits rebinding to another VM');
$a=['sourceHostId'=>$source,'vmUuid'=>$uuid,'replicationId'=>$legacy];$b=$a+['incarnation'=>$nonce];$b['replicationId']=$q['replicationId'];$oldDir=unmReplicaStateDirectory($a);$newDir=unmReplicaStateDirectory($b);ok($oldDir!==$newDir&&!str_starts_with($newDir,$oldDir.'/'),'New state artifacts are a disjoint sibling, not a child of retained legacy state');
foreach(['KEPT','DELETED'] as $state){$m=$q+['storage'=>[['destination'=>'backup/old']],'state'=>$state==='KEPT'?'RETIRED':'DELETED'];$r=$q+['state'=>$state,'mode'=>$state==='KEPT'?'keep':'delete','token'=>str_repeat('7',32)];$r['ack']=['success'=>true,'token'=>$r['token'],'mode'=>$r['mode'],'storageHash'=>unmReplicationStorageFingerprint($m['storage']),'partialRetained'=>false];unmReplicaAssertCycleEnded($m,$r);ok(true,'Fresh destination proof accepts a completed matching cycle');$bad=$r;unset($bad['ack']);deny(fn()=>unmReplicaAssertCycleEnded($m,$bad),'Missing destination receipt blocks recreation');$bad=$r;$bad['state']='CLEANING';deny(fn()=>unmReplicaAssertCycleEnded($m,$bad),'In-flight destination cleanup blocks new admission');$bad=$r;$bad['incarnation']=str_repeat('8',32);deny(fn()=>unmReplicaAssertCycleEnded($m,$bad),'Wrong destination incarnation cannot confirm prior retirement');}
echo "$checks replication-incarnation identity, admission, history and state-directory checks passed.\n";
