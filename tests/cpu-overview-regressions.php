<?php
// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
declare(strict_types=1);
require __DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/cpu-compatibility.php';
$checks=0;
function overviewCheck(bool $ok,string $why):void{global $checks;++$checks;if(!$ok)throw new RuntimeException($why);}
function overviewMode(string $id,string $feature='avx2'):array{
    $xml=unmCpuCompatibilityDefinition('<cpu mode="custom" match="exact"><model fallback="forbid">Cascadelake-Server</model><vendor>Intel</vendor><feature policy="require" name="'.$feature.'"/></cpu>');
    return ['id'=>$id,'status'=>'supported','deprecated'=>false,'cpuXml'=>$xml,'definitionHash'=>hash('sha256',$xml)];
}
function overviewReport(string $machine):array{return ['schemaVersion'=>2,'hostId'=>'local','hostname'=>'Squid Proxy','observedAt'=>'2026-11-08T19:00:00+00:00','displayOnly'=>true,'available'=>true,'complete'=>true,'context'=>['architecture'=>'x86_64','virtType'=>'kvm','vendor'=>'Intel','machine'=>$machine],'modes'=>[overviewMode('Cascadelake-Server-v5'),overviewMode('Skylake-Server-v5')],'cache'=>['identity'=>'hardware-one','cached'=>true,'refreshing'=>false,'status'=>'ready'],'error'=>''];}
$reports=['q35'=>overviewReport('pc-q35-10.2'),'pc'=>overviewReport('pc-i440fx-10.2')];$original=$reports;
$local=unmCpuCompatibilityOverview($reports);
overviewCheck($local['available']&&$local['complete']&&$local['displayOnly']&&$local['overviewVersion']===1,'Two cached reference checks form a display-only host overview.');
overviewCheck(count($local['modes'])===2&&array_column($local['modes'],'status')===['supported','supported'],'Only identical validated definitions are merged.');
overviewCheck($reports===$original,'Combining reports does not rewrite the underlying observations.');
overviewCheck($local['cache']['identity']===unmCpuCompatibilityOverview($reports)['cache']['identity'],'Repeated reads have a deterministic overview identity.');
overviewCheck(array_column($local['referenceContexts'],'machine')===['pc-q35-10.2','pc-i440fx-10.2'],'Exact reference versions remain internal metadata.');
$older=$reports;$older['q35']['context']['machine']='pc-q35-9.2';$older['pc']['context']['machine']='pc-i440fx-9.2';foreach($older as &$r)$r['hostId']='remote';unset($r);
$remote=unmCpuCompatibilityOverview($older);
overviewCheck(array_column(unmCpuCompatibilityCommon($local,$remote),'common')===[true,true],'Host CPU definitions can match across different supported reference versions.');
overviewCheck(!in_array(true,array_column(unmCpuCompatibilityCommon($reports['q35'],$older['q35']),'common'),true),'Machine-specific comparisons still require the exact machine context.');
overviewCheck(!in_array(true,array_column(unmCpuCompatibilityCommon($local,$reports['q35']),'common'),true),'An overview cannot be mistaken for a VM-specific observation.');
$different=$reports;$different['pc']['modes'][0]=overviewMode('Cascadelake-Server-v5','avx');$combined=unmCpuCompatibilityOverview($different);
overviewCheck($combined['available']&&!$combined['complete']&&$combined['referenceDifferences']===1,'Different Q35/i440fx definitions remain context-dependent.');
overviewCheck($combined['modes'][0]['status']==='unknown'&&!isset($combined['modes'][0]['cpuXml']),'A mismatching reference definition never becomes supported or an invented feature intersection.');
overviewCheck($combined['modes'][1]['status']==='supported','An uncertain level does not remove other independently validated definitions.');
overviewCheck($combined['cache']['identity']!==$local['cache']['identity'],'Changed cached content invalidates in-flight UI comparisons.');
$bad=$reports;$bad['pc']['modes'][0]['definitionHash']=str_repeat('0',64);$r=unmCpuCompatibilityOverview($bad);
overviewCheck($r['modes'][0]['status']==='unknown','Invalid cached hashes never imply support.');
$missing=$reports;array_pop($missing['pc']['modes']);$r=unmCpuCompatibilityOverview($missing);
overviewCheck($r['modes'][1]['status']==='unknown'&&!$r['complete'],'A level absent from one reference is unconfirmed, not inherited.');
$unsupported=$reports;$unsupported['pc']['modes'][0]['status']='unsupported';$r=unmCpuCompatibilityOverview($unsupported);
overviewCheck($r['modes'][0]['status']==='unsupported','Explicit reference failure cannot be promoted to support.');
$partial=$reports;$partial['pc']['modes'][0]=['id'=>'Cascadelake-Server-v5','status'=>'unknown'];$r=unmCpuCompatibilityOverview($partial);
overviewCheck($r['modes'][0]['status']==='unknown','Unknown cached observations stay unknown without retrying inspection.');
$deprecated=$reports;$deprecated['pc']['modes'][0]['deprecated']=true;$r=unmCpuCompatibilityOverview($deprecated);
overviewCheck($r['modes'][0]['deprecated'],'Deprecation in either reference is retained.');
$pending=$reports;$pending['pc']['available']=false;$pending['pc']['error']='Still collecting';$pending['pc']['cache']['refreshing']=true;$r=unmCpuCompatibilityOverview($pending);
overviewCheck(!$r['available']&&!$r['modes']&&$r['cache']['refreshing']&&str_contains($r['error'],'i440fx'),'A missing reference cannot produce a successful partial overview.');
foreach(['hostId'=>'other','schemaVersion'=>1,'displayOnly'=>false,'observedAt'=>'not a date','modes'=>[]] as $key=>$value){$bad=$reports;$bad['pc'][$key]=$value;$r=unmCpuCompatibilityOverview($bad);overviewCheck(!$r['available']&&!$r['modes'],'Malformed or mixed-identity reports fail closed.');}
foreach(['architecture'=>'aarch64','virtType'=>'qemu','vendor'=>'AMD','machine'=>'microvm'] as $key=>$value){$bad=$reports;$bad['pc']['context'][$key]=$value;overviewCheck(!unmCpuCompatibilityOverview($bad)['available'],'Reference context must remain x86_64 KVM, the same vendor and the expected family.');}
$bad=$reports;$bad['pc']['cache']['identity']='changed-hardware';overviewCheck(!unmCpuCompatibilityOverview($bad)['available'],'Hardware changes between the two reads discard the overview.');
$bad=$reports;foreach($bad as &$r)$r['cache']['identity']=[];unset($r);overviewCheck(!unmCpuCompatibilityOverview($bad)['available'],'Malformed matching cache identities cannot establish a stable CPU context.');
$bad=$reports;foreach($bad as &$r)$r['hostId']=['invalid'];unset($r);overviewCheck(!unmCpuCompatibilityOverview($bad)['available'],'Malformed matching host identities are rejected.');
$bad=$reports;$bad['pc']['modes'][]=$bad['pc']['modes'][0];overviewCheck(!unmCpuCompatibilityOverview($bad)['available'],'Duplicate cached identities are rejected.');
$times=$reports;$times['pc']['observedAt']='2026-11-08T18:00:00Z';overviewCheck(unmCpuCompatibilityOverview($times)['observedAt']==='2026-11-08T18:00:00Z','The overview timestamp is its oldest reference, not the request time.');

// Execute both real cached-only requests with just the authenticated transport mocked.
$peerCaps=['hostId'=>'remote','cpuCompatibilityVersion'=>2];$calls=[];
function unmHostId():string{return 'local';}
function unmPeer(string $id):array{return ['id'=>$id,'hostId'=>'remote'];}
function unmRemote(array $peer,string $command,int $timeout=60):array{
    global $peerCaps,$calls,$older;$calls[]=$command;
    if(str_ends_with($command,' capabilities'))return ['code'=>0,'stdout'=>json_encode($peerCaps),'stderr'=>''];
    if(!preg_match('~ cpu-compatibility [\'\"]([A-Za-z0-9+/=]+)[\'\"]$~D',$command,$m))throw new RuntimeException('Unexpected command');
    $request=json_decode(base64_decode($m[1]),true);if(!in_array($request['machine'],['q35','pc'],true))throw new RuntimeException('Unexpected machine selector');
    return ['code'=>0,'stdout'=>json_encode($older[$request['machine']]),'stderr'=>''];
}
function unmRun():never{throw new RuntimeException('A paired overview must not run local inspection.');}
$paired=unmCpuCompatibilityPeerOverview('peer');overviewCheck($paired['available']&&$paired['hostId']==='remote','Cached-report peers do not need a new wire operation for the overview.');
overviewCheck(count($calls)===3,'One current capability proof and exactly two cached RPCs are used.');
$peerCaps['cpuCompatibilityVersion']=1;$calls=[];
try{unmCpuCompatibilityPeerOverview('peer');throw new LogicException('Unexpected old peer success');}catch(RuntimeException $e){overviewCheck(count($calls)===1&&str_contains($e->getMessage(),'does not advertise cached'),'Old live-query peers are rejected before any CPU RPC.');}
echo "Automatic Q35/i440fx CPU overview: $checks assertions passed.\n";
