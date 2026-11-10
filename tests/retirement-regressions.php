<?php
// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
declare(strict_types=1);
namespace RetirementFixture;
require __DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/lib.php';
foreach(['cleanup-plan.php','replication-retirement.php','seed-cleanup.php'] as $name){
    $code=file_get_contents(__DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/'.$name);
    foreach(['unmCleanupRun','unmCleanupRead','unmCleanupStore','unmRetirementRpc'] as $f)$code=str_replace('function '.$f.'(','function unusedReal'.$f.'(',$code);
    eval('namespace RetirementFixture; use \\RuntimeException; use \\InvalidArgumentException; use \\Throwable; use \\DOMDocument; use \\DOMXPath;'.substr($code,strpos($code,'function ')));
}
const UNM_BOOT_DIR='/tmp/unmotion-retirement-fixture';
const UNM_SEEDS_DIR=UNM_BOOT_DIR.'/seeds';
const UNM_REPLICAS_DIR=UNM_BOOT_DIR.'/replicas';
$source='11111111-1111-4111-8111-111111111111';$destination='22222222-2222-4222-8222-222222222222';$uuid='33333333-3333-4333-8333-333333333333';$id=\unmReplicationId($uuid,$destination);$seedId='seed-'.str_repeat('a',24);$token=str_repeat('b',32);$checks=0;
$host=$source;$fs=[];$zfs=[];$holds=[];$properties=[];$mutations=[];$policy=[];$durable=[];$seed=[];$caps=1;$lost=false;$process=true;$defined=false;$callbacks=0;$refs=[];
function ok(bool $test,string $message):void{global $checks;++$checks;if(!$test)throw new \RuntimeException($message);}
function deny(callable $f,string $message):void{try{$f();}catch(\Throwable){ok(true,$message);return;}throw new \RuntimeException('Expected refusal: '.$message);}
function unmHostId(){global $host;return $host;}
function unmTransportPath($p){return $p;}
function unmTransportDataset($p){return \unmTransportDataset($p);}
function unmTransportUuid($p){return \unmTransportUuid($p);}
function unmTransportId($p){return \unmTransportId($p);}
function unmCleanupRead($p){global $fs;return $fs[$p]??[];}
function unmCleanupStore($p,$v){global $fs;$fs[$p]=$v;}
function unmAtomicJson($p,$v){unmCleanupStore($p,$v);}
function file_exists($p){global $fs;return isset($fs[$p])||\file_exists($p);}
function glob($p){global $fs;return array_values(array_unique(array_merge(\glob($p)?:[],array_values(array_filter(array_keys($fs),fn($key)=>fnmatch($p,$key))))));}
function is_dir($p){return true;}
function realpath($p){return $p;}
function rename($a,$b){global $mutations;$mutations[]=['archive',$a,$b];return true;}
function unlink($p){global $fs;unset($fs[$p]);return true;}
function unmReplicationPath($i){return UNM_BOOT_DIR.'/replications/'.$i;}
function unmReplicaPath($s,$i){return UNM_REPLICAS_DIR.'/'.$s.'/'.$i;}
function unmSeedPath($i){return UNM_SEEDS_DIR.'/'.$i;}
function unmSeed($i){global $seed;return $seed;}
function unmReplicationPolicy($i){global $policy;return $policy;}
function unmReplicationDurableState($i){global $durable;return $durable;}
function unmReplicationStorageFingerprint($s){return \unmReplicationStorageFingerprint($s);}
function unmPeer($p){global $destination;return ['id'=>'peer-fixture','hostId'=>$destination];}
function unmFindPeerByHostId($host){return ['id'=>'peer-fixture','hostId'=>$host];}
function unmAcquireReplicationPolicyLock($i,$wait=false){return fopen('php://temp','w+');}
function unmReleaseReplicationPolicyLock($f){fclose($f);}
function unmRecoveryAcquireVmLock($u){return fopen('php://temp','w+');}
function unmRecoveryAcquireLock($i){return fopen('php://temp','w+');}
function unmRecoveryReleaseLock($f){fclose($f);}
function unmRecoverySourcePath($i){return unmReplicationPath($i).'/recovery.json';}
function unmRecoveryLoad($p){return unmCleanupRead($p);}
function unmRecoveryDisarm($i){global $fs;$fs[unmRecoverySourcePath($i)]=['state'=>'REPLICATION_ONLY'];}
function unmProcessRecordedWorker($p,$s,$a){global $process;return $process?['pid'=>42]:null;}
function unmLoadConfig(){return ['image_dir'=>'/mnt/cache/domains'];}
function unmValidateReplicaRequestIdentity($q){global $host;$r=array_intersect_key($q,array_flip(['sourceHostId','destinationHostId','replicationId','vmUuid','vmName']));if(($q['destinationHostId']??'')!==$host)throw new \RuntimeException('Destination identity mismatch');return $r;}
function unmAssertReplicaVmUuidUndefined($u){global $defined;if($defined)throw new \RuntimeException('Destination VM is defined');}
function unmVerifyReplicaDestinationInert($s){global $properties;if(($properties[$s['destination']]['readonly']??'on')!=='on')throw new \RuntimeException('Writable replica');}
function unmReplicaSnapshotHoldTags($s){global $holds;return $holds[$s]??[];}
function unmReplicaZfsProperty($s,$p){global $properties;return $properties[$s][$p]??'-';}
function unmRemote($peer,$cmd,$timeout=60){global $caps;return ['code'=>0,'stdout'=>json_encode(['protocolVersion'=>7,'retirementVersion'=>$caps,'seedCleanupVersion'=>$caps]),'stderr'=>''];}
function unmTransportReservationPath($p,$i){return UNM_BOOT_DIR.'/transport/'.$p['id'].'/'.$i.'.json';}
function unmCleanupRun($a){
    global $zfs,$holds,$properties,$mutations,$refs;
    if($a[0]==='virsh'&&$a[1]==='list')return implode("\n",array_keys($refs));
    if($a[0]==='virsh'&&$a[1]==='dumpxml')return $refs[$a[2]];
    if($a[0]==='sync')return '';
    if($a[0]!=='zfs')throw new \RuntimeException('Unexpected command: '.json_encode($a));
    if($a[1]==='list')return implode("\n",array_map(fn($r)=>implode("\t",$r),array_values($zfs)));
    $mutations[]=$a;
    if($a[1]==='release'){$holds[$a[3]]=array_values(array_diff($holds[$a[3]]??[],[$a[2]]));return '';}
    if($a[1]==='destroy'){unset($zfs[$a[2]],$holds[$a[2]]);return '';}
    if($a[1]==='receive'&&$a[2]==='-A'){$properties[$a[3]]['receive_resume_token']='-';return '';}
    throw new \RuntimeException('Unexpected mutation');
}
function unmRetirementRpc($peer,$cmd,$q){
    global $host,$source,$destination,$lost,$callbacks;
    $old=$host;
    try{
        if($cmd==='retirement-source-proof'){$host=$source;++$callbacks;return unmRetirementSourceProof($q);}
        $host=$destination;$r=$cmd==='seed-cleanup'?unmSeedCleanupRemote(['id'=>'peer-fixture','hostId'=>$source],$q):unmReplicaRetirement($q);
        if($lost&&$q['phase']==='commit'){$lost=false;throw new \RuntimeException('Simulated lost acknowledgement');}return $r;
    }finally{$host=$old;}
}
function resetFixture(string $mode='delete',bool $discard=false):void{
    global $source,$destination,$uuid,$id,$token,$fs,$zfs,$holds,$properties,$mutations,$policy,$durable,$host,$caps,$lost,$process,$defined,$callbacks,$refs;
    $host=$source;$caps=1;$lost=false;$process=true;$defined=false;$callbacks=0;$fs=[];$mutations=[];$properties=[];$refs=[];
    $storage=[['kind'=>'dataset','source'=>'cache/Squid Proxy','destination'=>'backup/Squid Proxy','destinationMountpoint'=>'/mnt/backup/Squid Proxy']];
    $policy=['id'=>$id,'vmUuid'=>$uuid,'vmName'=>'Squid Proxy','peerId'=>'peer-fixture','peerHostId'=>$destination,'storage'=>$storage,'enabled'=>true];$durable=[];
    $snapshot=\unmReplicaExpectedSnapshotName($id,1);$zfs=[];$holds=[];
    foreach(['cache/Squid Proxy','backup/Squid Proxy'] as $base){$zfs[$base]=[$base,'filesystem',$base[0]==='c'?'100':'200'];$zfs[$base.'@'.$snapshot]=[$base.'@'.$snapshot,'snapshot','300'];$holds[$base.'@'.$snapshot]=['unmotion:replication:'.$id];}
    $fs[unmReplicaPath($source,$id).'/manifest.json']=['sourceHostId'=>$source,'destinationHostId'=>$destination,'replicationId'=>$id,'vmUuid'=>$uuid,'vmName'=>'Squid Proxy','storage'=>$storage,'points'=>[['id'=>'point-1']]];
    $fs[unmRetirementPath($id)]=unmRetirementIdentity($policy)+['schemaVersion'=>1,'token'=>$token,'mode'=>$mode,'discardPartial'=>$discard,'storageHash'=>unmReplicationStorageFingerprint($storage),'state'=>'ENDING'];
}
resetFixture();$r=unmRetirementSourceRun($id,$token);ok($r['state']==='ENDED_DELETE','Healthy delete ends replication');ok(!isset($zfs['backup/Squid Proxy']),'Exact destination removed');ok(isset($zfs['cache/Squid Proxy']),'Writable source dataset retained');ok(count($zfs)===1,'Source incremental base released after destination acknowledgement');ok($callbacks===1,'Fresh source callback required');ok($fs[unmReplicationPath($id).'/policy.json']['enabled']===false,'Source schedule disabled');
ok(!array_filter($mutations,fn($a)=>$a[0]==='virsh'),'No source power operation');
resetFixture('keep');$r=unmRetirementSourceRun($id,$token);ok($r['state']==='ENDED_KEEP'&&isset($zfs['backup/Squid Proxy']),'Keep seals/inventories replica data');ok($fs[unmReplicaPath($source,$id).'/manifest.json']['state']==='RETIRED','Retained manifest remains visible');
resetFixture();$process=false;$r=$fs[unmRetirementPath($id)];$r['updatedAt']=gmdate('c',time()-60);ok(unmRetirementDisplay($id,$r)['state']==='BLOCKED','Dead retirement worker offers retry, including after reboot');ok($fs[unmRetirementPath($id)]['state']==='ENDING','Display reconciliation does not mutate authority or resume cleanup');$r['updatedAt']=gmdate('c');ok(unmRetirementDisplay($id,$r)['state']==='ENDING','New launcher gets a bounded registration grace');$process=true;$r['updatedAt']=gmdate('c',time()-60);ok(unmRetirementDisplay($id,$r)['state']==='ENDING','Exact active worker remains ending');
resetFixture();$caps=0;deny(fn()=>unmRetirementSourceRun($id,$token),'Mixed peer refuses unsupported operation');ok(!$mutations,'Mixed peer causes no cleanup');
resetFixture();$name=\unmReplicaExpectedSnapshotName($id,1);$holds['backup/Squid Proxy@'.$name][]='foreign';deny(fn()=>unmRetirementSourceRun($id,$token),'Foreign hold blocks deletion');ok(!$mutations,'Foreign hold preserves source base and all destination data');
resetFixture();$zfs['backup/Squid Proxy/child']=['backup/Squid Proxy/child','filesystem','777'];deny(fn()=>unmRetirementSourceRun($id,$token),'Child dataset blocks deletion');ok(!$mutations,'Child blocks all mutations');
resetFixture();$zfs['backup/Squid Proxy@manual']=['backup/Squid Proxy@manual','snapshot','778'];deny(fn()=>unmRetirementSourceRun($id,$token),'Unrelated snapshot blocks deletion');ok(!$mutations,'Unrelated snapshots preserved');
resetFixture();$properties['backup/Squid Proxy']['receive_resume_token']='resume-token';deny(fn()=>unmRetirementSourceRun($id,$token),'Partial receive requires explicit discard');ok(!$mutations,'Token and base preserved');
resetFixture('keep');$properties['backup/Squid Proxy']['receive_resume_token']='resume-token';$r=unmRetirementSourceRun($id,$token);ok(!empty($r['sourceBasesRetained'])&&count($zfs)===4,'Keep partial receive also keeps source incremental bases');
resetFixture('delete',true);$properties['backup/Squid Proxy']['receive_resume_token']='resume-token';$r=unmRetirementSourceRun($id,$token);ok($r['state']==='ENDED_DELETE'&&in_array(['zfs','receive','-A','backup/Squid Proxy'],$mutations,true),'Explicit partial discard uses exact receive abort');
resetFixture();$lost=true;deny(fn()=>unmRetirementSourceRun($id,$token),'Lost acknowledgement interrupts source cleanup');ok(count($zfs)===2&&isset($zfs['cache/Squid Proxy@'.\unmReplicaExpectedSnapshotName($id,1)]),'Lost ack retains source base');$r=unmRetirementSourceRun($id,$token);ok($r['state']==='ENDED_DELETE'&&count($zfs)===1,'Retry confirms receipt then cleans source once');
resetFixture();$process=false;deny(fn()=>unmRetirementSourceRun($id,$token),'Stale worker cannot prove source retirement');ok(!$mutations,'Stale proof has no mutation');
resetFixture();$defined=true;deny(fn()=>unmRetirementSourceRun($id,$token),'Defined destination VM blocks cleanup');ok(!$mutations,'Defined VM preserved');
resetFixture();$properties['backup/Squid Proxy']['readonly']='off';deny(fn()=>unmRetirementSourceRun($id,$token),'Writable destination blocks cleanup');ok(!$mutations,'Non-inert destination preserved');
resetFixture();$fs[unmRecoverySourcePath($id)]=['state'=>'RECOVERED_RUNNING','armed'=>false,'activationId'=>'act-test'];deny(fn()=>unmRetirementSourceRun($id,$token),'Activation metadata blocks unarmed cleanup');ok(!$mutations,'Unresolved authority retained');
resetFixture();$fs[UNM_BOOT_DIR.'/native-managed/'.$uuid.'.json']=['replicationId'=>$id];deny(fn()=>unmRetirementSourceRun($id,$token),'Missing managed authority blocks cleanup');ok(!$mutations,'Missing authority does not release fences or source bases');
resetFixture();unset($fs[unmReplicaPath($source,$id).'/manifest.json']);deny(fn()=>unmRetirementSourceRun($id,$token),'Missing ownership cannot adopt existing datasets');ok(!$mutations,'Unowned existing destination preserved');
resetFixture();$plan=unmCleanupZfsPlan($policy['storage'][0],'/\Aunmotion-rpl-[a-f0-9]+-g[1-9][0-9]*\z/D','unmotion:replication:'.$id,false);$zfs['backup/Squid Proxy'][2]='999';deny(fn()=>unmCleanupDeleteZfs($plan,fn()=>null),'Replaced dataset GUID blocks replay');ok(!$mutations,'Replaced dataset preserved');
resetFixture();$seed=['id'=>$seedId,'vmUuid'=>$uuid,'peerId'=>'peer-fixture','protocolVersion'=>5];deny(fn()=>unmSeedCleanupSource($seedId),'Legacy seed cannot fabricate protocol-7 ownership');ok(!$mutations,'Legacy source base preserved');
resetFixture();$refs[$uuid]='<domain><devices><disk><source file="/mnt/backup/Squid Proxy/disk.qcow2"/></disk></devices></domain>';deny(fn()=>unmRetirementSourceRun($id,$token),'Another VM disk reference blocks cleanup');ok(!$mutations,'Referenced storage retained');
resetFixture();$refs[$uuid]='<domain><devices><disk><source file="/mnt/user/Squid Proxy/disk.qcow2"/></disk></devices></domain>';deny(fn()=>unmRetirementSourceRun($id,$token),'FUSE alias protects another VM reference');ok(!$mutations,'FUSE-referenced storage retained');
resetFixture();$refs[$uuid]='<domain><devices><disk><source dev="/dev/zvol/backup/Squid+Proxy"/></disk></devices></domain>';deny(fn()=>unmCleanupAssertUnreferenced([['kind'=>'zvol','destination'=>'backup/Squid Proxy']]),'Observed encoded zvol alias is a reference, not deletion permission');ok(!$mutations,'Encoded volume reference retained');
function seedFixture():void{
    resetFixture();global $seed,$seedId,$uuid,$source,$zfs,$holds,$fs,$token;
    $seed=['id'=>$seedId,'vmUuid'=>$uuid,'peerId'=>'peer-fixture','protocolVersion'=>7,'storage'=>[['kind'=>'dataset','source'=>'cache/Squid Proxy','destination'=>'backup/Squid Proxy']],'state'=>'REMOVING'];
    foreach(array_keys($zfs) as $name)if(str_contains($name,'@')){unset($zfs[$name],$holds[$name]);$new=explode('@',$name)[0].'@unmotion-seed-'.$seedId.'-g1';$zfs[$new]=[$new,'snapshot','300'];$holds[$new]=['unmotion:'.$seedId];}
    $fs[unmTransportReservationPath(['id'=>'peer-fixture'],$seedId)]=['id'=>$seedId,'vmUuid'=>$uuid,'peerId'=>'peer-fixture','sourceHostId'=>$source,'storage'=>[['kind'=>'dataset','destination'=>'backup/Squid Proxy']],'files'=>[]];
}
seedFixture();$r=unmSeedCleanupSource($seedId);ok($r['success']&&count($zfs)===1,'Seed cleanup completes destination then source');ok(end($mutations)[0]==='archive','Cleanup archives logs and history instead of discarding them');
seedFixture();$lost=true;deny(fn()=>unmSeedCleanupSource($seedId),'Seed lost ack blocks source cleanup');ok(count($zfs)===2,'Seed source base survives lost destination acknowledgement');$r=unmSeedCleanupSource($seedId);ok($r['success']&&count($zfs)===1,'Seed retry confirms durable destination receipt');
seedFixture();unset($fs[unmTransportReservationPath(['id'=>'peer-fixture'],$seedId)]);deny(fn()=>unmSeedCleanupSource($seedId),'Missing reservation cannot be inferred from a seed name');ok(!$mutations,'Missing reservation preserves source hold');
seedFixture();$name='backup/Squid Proxy@unmotion-seed-'.$seedId.'-g1';$holds[$name][]='foreign';deny(fn()=>unmSeedCleanupSource($seedId),'Seed foreign hold blocks entire preflight');ok(!$mutations,'Failed seed cleanup preserves preparation storage');
seedFixture();$q=['sourceHostId'=>$source,'destinationHostId'=>$destination,'seedId'=>$seedId,'vmUuid'=>$uuid,'token'=>$token,'discardPartial'=>true,'phase'=>'review'];$host=$destination;$pre=unmSeedCleanupRemote(['id'=>'peer-fixture','hostId'=>$source],$q);$q['phase']='commit';$q['review']=$pre['review'];unmSeedCleanupRemote(['id'=>'peer-fixture','hostId'=>$source],$q);$fs[unmTransportReservationPath(['id'=>'peer-fixture'],$seedId)]=['id'=>$seedId,'vmUuid'=>$uuid,'peerId'=>'peer-fixture','sourceHostId'=>$source,'storage'=>[],'instance'=>'new'];$count=count($mutations);deny(fn()=>unmSeedCleanupRemote(['id'=>'peer-fixture','hostId'=>$source],$q),'Old cleanup receipt cannot remove a replacement reservation');ok(count($mutations)===$count,'Replayed receipt causes no data mutation');
resetFixture();$newId=\unmReplicationId($uuid,$destination,str_repeat('c',32));$newSnap=\unmReplicaExpectedSnapshotName($newId,1);$newDest='backup/Squid Proxy new cycle';$newStorage=[['kind'=>'dataset','source'=>'cache/Squid Proxy','destination'=>$newDest,'destinationMountpoint'=>'/mnt/'.$newDest]];
$zfs[$newDest]=[$newDest,'filesystem','900'];$zfs[$newDest.'@'.$newSnap]=[$newDest.'@'.$newSnap,'snapshot','901'];$holds[$newDest.'@'.$newSnap]=['unmotion:replication:'.$newId];$zfs['cache/Squid Proxy@'.$newSnap]=['cache/Squid Proxy@'.$newSnap,'snapshot','901'];$holds['cache/Squid Proxy@'.$newSnap]=['unmotion:replication:'.$newId];
$fs[unmReplicaPath($source,$newId).'/manifest.json']=['replicationId'=>$newId,'sourceHostId'=>$source,'destinationHostId'=>$destination,'vmUuid'=>$uuid,'storage'=>$newStorage,'state'=>'READY'];
$beforeNew=array_filter($zfs,fn($row)=>$row[0]===$newDest||str_contains($row[0],$newSnap));$r=unmRetirementSourceRun($id,$token);ok($r['state']==='ENDED_DELETE','Older retained cycle can be deleted beside a new cycle');ok(array_filter($zfs,fn($row)=>$row[0]===$newDest||str_contains($row[0],$newSnap))===$beforeNew,'Old cleanup preserves new destination GUIDs and source incremental base');ok($holds['cache/Squid Proxy@'.$newSnap]===['unmotion:replication:'.$newId],'New source hold retained');
$q=unmRetirementIdentity($policy)+['token'=>$token,'mode'=>'delete','discardPartial'=>false,'phase'=>'review','storage'=>$newStorage,'sourceSnapshots'=>[]];$host=$destination;$beforeCount=count($mutations);deny(fn()=>unmReplicaRetirement($q),'Old retirement cannot be redirected onto new-cycle storage');ok(count($mutations)===$beforeCount,'Redirected old cleanup causes no mutations');
echo "$checks retirement, exact cleanup, partial receive, ownership and lost-ack checks passed.\n";
