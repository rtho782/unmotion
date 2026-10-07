<?php
declare(strict_types=1);
require __DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/native-fence.php';
$root=sys_get_temp_dir().'/unmotion-native-fence-'.bin2hex(random_bytes(8));mkdir($root,0700);$base=$root.'/base';$run=$root.'/run/lifecycle';$locks=$root.'/locks';mkdir($base,0700);mkdir($locks,0700);$checks=0;
function check(bool $yes,string $name):void{global $checks;if(!$yes)throw new RuntimeException('FAIL: '.$name);++$checks;}
function denied(callable $call,string $name):void{$failed=false;try{$call();}catch(Throwable $e){$failed=true;}check($failed,$name);}
function removeTestTree(string $p):void{foreach(scandir($p)?:[] as $n){if($n==='.'||$n==='..')continue;$child=$p.'/'.$n;is_dir($child)&&!is_link($child)?removeTestTree($child):unlink($child);}rmdir($p);}
$uuid='74200000-1111-4111-8111-000000000006';$id='repl-'.str_repeat('a',24);$host='11111111-1111-4111-8111-111111111111';$peer='22222222-2222-4222-8222-222222222222';$boot=unmNativeBoot();file_put_contents($base.'/host_id',$host);
$xml='<domain><name>Squid Proxy</name><uuid>'.$uuid.'</uuid><devices/></domain>';
$call=static fn(string $op,string $content='')=>unmNativeFence($op,$op==='release'?'end':'begin',$content?:$xml,$base,$run,$locks,$boot);
$path=$base.'/replications/'.$id.'/recovery.json';$policy=['id'=>$id,'vmUuid'=>$uuid,'peerHostId'=>$peer];$r=['schemaVersion'=>1,'protocolVersion'=>6,'recoveryProtocolVersion'=>1,'replicationId'=>$id,'vmUuid'=>$uuid,'sourceHostId'=>$host,'destinationHostId'=>$peer,'sourceBootId'=>$boot,'destinationBootId'=>$boot,'term'=>1,'side'=>'source','state'=>'STANDBY','authority'=>'SOURCE','armed'=>true,'managedAutostart'=>true,'startAuthorization'=>['requestId'=>'start-'.str_repeat('b',32)]];
$permit=['schemaVersion'=>1,'replicationId'=>$id,'vmUuid'=>$uuid,'sourceHostId'=>$host,'destinationHostId'=>$peer,'sourceBootId'=>$boot,'term'=>1,'requestId'=>$r['startAuthorization']['requestId'],'expiresAt'=>gmdate('c',time()+25)];
$write=static function(array $record)use($path){unmNativeWrite($path,$record);};$issue=static function()use($run,$id,$permit){unmNativeWrite($run.'/start-permits/'.$id.'.json',$permit);};
try{
 $call('prepare');check(true,'unmanaged native start unaffected');
 unmNativeWrite(dirname($path).'/policy.json',$policy);$write($r);unmNativeMarkManaged($uuid,$id,'source',$base);
 denied(fn()=>$call('prepare'),'managed source missing permit denied');denied(fn()=>unmNativeAssertRemovable($base),'armed uninstall denied');
 foreach(['migrate','restore','attach'] as $op)denied(fn()=>$call($op),'alternate '.$op.' denied');
 $issue();$call('prepare');check(is_file($run.'/start-permits/'.$id.'.json'),'prepare retains permit until final start');check(unmNativeJson($run.'/native-admissions/'.$uuid.'.json')['stage']==='PREPARING','preparation journal visible to grant interlock');
 denied(fn()=>$call('prepare'),'duplicate native prepare denied');$call('start');check(!file_exists($run.'/start-permits/'.$id.'.json'),'start consumes source permit');check(unmNativeJson($run.'/native-admissions/'.$uuid.'.json')['stage']==='STARTING','start journal visible');denied(fn()=>$call('start'),'one-shot replay denied');$call('release');check(!file_exists($run.'/native-admissions/'.$uuid.'.json'),'release clears admission');
 foreach(['authority'=>'DESTINATION','sourceBootId'=>$peer,'term'=>2,'state'=>'FENCED'] as $key=>$bad){$write(array_replace($r,[$key=>$bad]));$issue();denied(fn()=>$call('prepare'),'source mismatched '.$key.' denied');}$write($r);
 $write($r+['hold'=>['holdUntil'=>'forever']]);$issue();denied(fn()=>$call('prepare'),'source graceful hold denied');$write($r);
 unmNativeWrite($run.'/start-permits/'.$id.'.json',array_replace($permit,['expiresAt'=>gmdate('c',time()-1)]));denied(fn()=>$call('prepare'),'expired source permit denied');
 $unarmed=array_replace($r,['state'=>'REPLICATION_ONLY','term'=>0,'armed'=>false,'managedAutostart'=>false]);$write($unarmed);$call('prepare');check(true,'term-zero replication-only unaffected');unmNativeAssertRemovable($base);check(true,'inactive uninstall allowed');
 $write($unarmed+['claim'=>['activationId'=>'act-x']]);denied(fn()=>$call('prepare'),'contradictory inactive record denied');$write($r);
 unlink($path);denied(fn()=>$call('prepare'),'missing exact managed authority denied');$other='repl-'.str_repeat('c',24);unmNativeWrite($base.'/replications/'.$other.'/recovery.json',array_replace($unarmed,['replicationId'=>$other]));denied(fn()=>$call('prepare'),'unrelated inactive policy cannot mask missing managed authority');unlink($base.'/replications/'.$other.'/recovery.json');$write($r);
 file_put_contents($path,'{broken');denied(fn()=>$call('prepare'),'corrupt record denied with policy fallback');$write($r);unmNativeWrite(dirname($path).'/policy.json',array_replace($policy,['vmUuid'=>$peer]));denied(fn()=>$call('prepare'),'policy record UUID disagreement denied');unmNativeWrite(dirname($path).'/policy.json',$policy);
 $write(array_replace($r,['vmUuid'=>'malformed']));denied(fn()=>$call('prepare'),'malformed inventory identity cannot become unmanaged');$write($r);
 denied(fn()=>$call('prepare',str_replace('<domain>','<!DOCTYPE domain [<!ENTITY x SYSTEM "file:///etc/passwd">]><domain>',$xml)),'XML entities rejected');
 unlink($path);unlink($base.'/native-managed/'.$uuid.'.json');
 file_put_contents($base.'/host_id',$peer);$act='act-'.str_repeat('d',24);$selection=str_repeat('e',64);$disk='/mnt/cache/domains/.unmotion-activations/'.$uuid.'/'.$act.'/vdisk.qcow2';
 $dest=array_replace($r,['side'=>'destination','state'=>'RECOVERED_STOPPED','authority'=>'DESTINATION','managedAutostart'=>false,'activationId'=>$act,'claim'=>['kind'=>'coordinated','selectionHash'=>$selection],'activation'=>['activationId'=>$act,'phase'=>'STARTING','selectionHash'=>$selection,'objects'=>[['mappings'=>['/old/disk'=>$disk]]]],'startGrant'=>['selectionHash'=>$selection,'sourceHostId'=>$host,'issuedAt'=>gmdate('c'),'expiresAt'=>gmdate('c',time()+120)]]);
 $destPath=$base.'/replicas/'.$host.'/'.$id.'/recovery.json';unmNativeWrite($destPath,$dest);unmNativeMarkManaged($uuid,$id,'destination',$base);
 $dx='<domain><name>Squid Proxy</name><uuid>'.$uuid.'</uuid><metadata><um:activation xmlns:um="urn:unmotion:recovery:1" replicationId="'.$id.'">'.$act.'</um:activation></metadata><devices><disk type="file" device="disk"><source file="'.$disk.'"/></disk></devices></domain>';
 denied(fn()=>$call('prepare',$dx),'destination manual start without permit denied');unmNativeIssueDestinationPermit($dest,$run);$call('prepare',$dx);$call('start',$dx);check(!file_exists($run.'/destination-permits/'.$uuid.'.json'),'destination one-shot consumed');$call('release',$dx);
 unmNativeIssueDestinationPermit($dest,$run);denied(fn()=>$call('prepare',str_replace($disk,'/etc/shadow',$dx)),'destination changed disk denied');
 $dest['activation']['phase']='DEFINED_STOPPED';unmNativeWrite($destPath,$dest);denied(fn()=>$call('prepare',$dx),'destination direct restart requires managed STARTING journal');
 check(unmNativeProofStatus($run)['ready']===false,'missing native callback proof is not ready');
 denied(fn()=>unmNativeDaemon(getmypid()),'manual hook caller is not libvirt proof');
 echo 'Native pre-start fencing regressions passed: '.$checks." checks.\n";
}finally{removeTestTree($root);}
