<?php
// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
declare(strict_types=1);
require __DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/cpu-compatibility.php';
$checks=0;
function cpuCheck(bool $ok,string $message): void {global $checks;$checks++;if(!$ok)throw new RuntimeException($message);}
function cpuReject(callable $call,string $message): void {try {$call();}catch(Throwable $e){cpuCheck(true,$message);return;}cpuCheck(false,$message);}
$baseline='<cpu mode="custom" match="exact"><model fallback="forbid">Skylake-Server-IBRS</model><vendor>Intel</vendor><feature policy="disable" name="rtm"/><feature name="avx2" policy="require"/><feature policy="disable" name="hle"/></cpu>';
$canonical=unmCpuCompatibilityDefinition($baseline);
$reordered='<cpu match="exact" mode="custom"><model fallback="forbid">Skylake-Server-IBRS</model><vendor>Intel</vendor><feature name="hle" policy="disable"/><feature policy="disable" name="rtm"/><feature policy="require" name="avx2"/></cpu>';
cpuCheck($canonical===unmCpuCompatibilityDefinition($reordered),'Feature/attribute order does not change the definition.');
cpuCheck(str_contains($canonical,'check="full"'),'Exact CPU checks are required.');
foreach (['../../q35','q35;reboot',"q35\n",[],null] as $machine) {
    if ($machine===null) continue;
    cpuReject(fn()=>unmCpuCompatibilityRequest(['machine'=>$machine]),'Machine input is constrained.');
}
cpuReject(fn()=>unmCpuCompatibilityRequest(['shell'=>'whoami']),'Only a machine selector is accepted.');
cpuCheck(unmCpuCompatibilityRequest([])==='q35','Default machine selector.');
foreach ([str_replace('custom','host-passthrough',$baseline),str_replace('forbid','allow',$baseline),str_replace('</cpu>','<cache mode="passthrough"/></cpu>',$baseline),str_replace('</cpu>','<feature name="invtsc" policy="require"/></cpu>',$baseline),str_replace('</cpu>','<feature name="rtm" policy="require"/></cpu>',$baseline),'<!DOCTYPE cpu [<!ENTITY x SYSTEM "file:///etc/passwd">]>'.$baseline,str_replace('</cpu>','<!--hidden--></cpu>',$baseline)] as $bad) cpuReject(fn()=>unmCpuCompatibilityDefinition($bad),'Unsafe/ambiguous CPU XML is rejected.');
$caps='<domainCapabilities><path>/usr/bin/qemu-system-x86_64</path><domain>kvm</domain><machine>pc-q35-10.2</machine><arch>x86_64</arch><cpu><mode name="host-model"><vendor>Intel</vendor></mode><mode name="custom" supported="yes"><model usable="yes">Skylake-Server-v3</model><model usable="no">Skylake-Server-v2</model><model usable="no">EPYC-v1</model><model usable="unknown">Future-v1</model><model usable="yes">Skylake-Server</model></mode></cpu></domainCapabilities>';
$calls=[];
$run=function(array $args,?string $input,int $timeout) use (&$calls,$caps,$baseline): array {
    $calls[]=['args'=>$args,'input'=>$input,'timeout'=>$timeout];
    cpuCheck($args[0]==='virsh','Only libvirt read queries are issued.');
    return ['code'=>0,'stdout'=>$args[1]==='domcapabilities'?$caps:($args[1]==='hypervisor-cpu-baseline'?$baseline:'compatible'),'stderr'=>''];
};
$local=unmCpuCompatibilityInspect([],$run,'local','Skylake');
cpuCheck($local['available'] && !$local['complete'],'Unknown usability remains unknown and marks an incomplete report.');
cpuCheck(count($local['modes'])===4,'Versioned Intel/AMD models are discovered; aliases are excluded.');
$byId=array_column($local['modes'],null,'id');
cpuCheck($byId['Skylake-Server-v3']['status']==='supported','Expanded CPU passes local comparison.');
cpuCheck($byId['Skylake-Server-v2']['status']==='unsupported','Unsupported TSX model remains unsupported.');
cpuCheck($byId['Future-v1']['status']==='unknown','An unknown usable flag never becomes supported.');
cpuCheck(count($calls)===3,'Only a supported candidate is expanded and compared.');
cpuCheck(in_array('--migratable',$calls[1]['args'],true) && in_array('--features',$calls[1]['args'],true),'Expand migratable features.');
cpuCheck(in_array('pc-q35-10.2',$calls[2]['args'],true) && in_array('--error',$calls[2]['args'],true),'Compare against the resolved machine and require compatibility.');
cpuCheck($calls[2]['input']===$canonical,'The exact expanded definition is validated, not just its model name.');
$remote=$local;$remote['hostId']='remote';
$rows=array_column(unmCpuCompatibilityCommon($local,$remote),null,'id');
cpuCheck($rows['Skylake-Server-v3']['common']===true,'Identical validated definitions are common.');
cpuCheck($rows['Skylake-Server-v2']['common']===false,'An unsupported mode is not common.');
cpuCheck($rows['Future-v1']['common']===null,'Unknown is distinct from unsupported.');
$remote['modes'][array_search('Skylake-Server-v3',array_column($remote['modes'],'id'),true)]['cpuXml']=str_replace('avx2','avx',$canonical);
$rows=array_column(unmCpuCompatibilityCommon($local,$remote),null,'id');
cpuCheck($rows['Skylake-Server-v3']['common']===false,'Matching names with different masks are not common.');
foreach (['machine'=>'pc-q35-9.2','vendor'=>'AMD','architecture'=>'aarch64'] as $field=>$value) {
    $other=$local;$other['context'][$field]=$value;
    cpuCheck(!in_array(true,array_column(unmCpuCompatibilityCommon($local,$other),'common'),true),'Mismatching context cannot report common CPUs.');
}
cpuCheck(!in_array(true,array_column(unmCpuCompatibilityCommon($local,['available'=>false]),'common'),true),'Offline peer cannot inherit prior success.');
cpuCheck(!in_array(true,array_column(unmCpuCompatibilityCommon($local,null),'common'),true),'Local-only checks do not imply peer support.');
$failure=fn()=>['code'=>1,'stdout'=>'','stderr'=>'VM Manager unavailable'];
$unavailable=unmCpuCompatibilityInspect([],$failure,'local','host');
cpuCheck(!$unavailable['available'] && !$unavailable['modes'] && $unavailable['error']!=='','Unavailable libvirt never reports a successful empty list.');
$failedCompare=function($args,$input,$timeout) use ($run) {return $args[1]==='hypervisor-cpu-compare'?['code'=>1,'stdout'=>'','stderr'=>'missing CPU features']:$run($args,$input,$timeout);};
$failed=unmCpuCompatibilityInspect([],$failedCompare,'local','host');
$failedById=array_column($failed['modes'],null,'id');
cpuCheck($failedById['Skylake-Server-v3']['status']==='unknown' && !isset($failedById['Skylake-Server-v3']['cpuXml']),'Failed comparison cannot advertise a supported mode.');
$amdCaps=str_replace(['Intel','usable="no">EPYC-v1','usable="yes">Skylake-Server-v3'],['AMD','usable="yes">EPYC-v1','usable="no">Skylake-Server-v3'],$caps);
$amdRun=fn($args,$input,$timeout)=>['code'=>0,'stdout'=>$args[1]==='domcapabilities'?$amdCaps:($args[1]==='hypervisor-cpu-baseline'?str_replace(['Skylake-Server-IBRS','Intel'],['EPYC','AMD'],$baseline):'compatible'),'stderr'=>''];
$amd=unmCpuCompatibilityInspect([],$amdRun,'amd','AMD host');$amdModes=array_column($amd['modes'],null,'id');
cpuCheck($amdModes['EPYC-v1']['status']==='supported' && $amd['context']['vendor']==='AMD','AMD levels use the same validated discovery path.');

// Exercise capability negotiation and peer identity without contacting a real host.
$peerCaps=['hostId'=>'remote'];$peerReport=$local;$peerReport['hostId']='remote';$peerReport['cache']=['cached'=>true,'status'=>'ready'];$remoteCalls=[];
function unmHostId(): string {return 'local';}
function unmPeer(string $id): array {return ['id'=>$id,'hostId'=>'remote'];}
function unmRemote(array $peer,string $command,int $timeout=60): array {
    global $peerCaps,$peerReport,$remoteCalls;$remoteCalls[]=$command;
    return ['code'=>0,'stdout'=>json_encode(str_ends_with($command,' capabilities')?$peerCaps:$peerReport),'stderr'=>''];
}
cpuReject(fn()=>unmCpuCompatibilityPeer('peer','q35'),'An older protocol-7 peer has no optional CPU support.');
cpuCheck(count($remoteCalls)===1,'Older peers never receive an unsupported operation.');
$peerCaps['cpuCompatibilityVersion']=1;cpuReject(fn()=>unmCpuCompatibilityPeer('peer','q35'),'Unpublished live-query schema cannot trigger remote calculation.');
$peerCaps['cpuCompatibilityVersion']=2;
cpuCheck(unmCpuCompatibilityPeer('peer','pc-q35-10.2')['hostId']==='remote','Negotiated, identity-bound CPU report is accepted.');
$peerReport['hostId']='another-host';cpuReject(fn()=>unmCpuCompatibilityPeer('peer','q35'),'Peer response identity is bound to pairing.');
$peerCaps['hostId']='local';cpuReject(fn()=>unmCpuCompatibilityPeer('peer','q35'),'Self-host capability response is rejected.');

// Execute the real restricted dispatcher with only its process runner replaced.
function unmTransportRun(array $argv,bool $stream=false): array {return ['code'=>0,'stdout'=>json_encode($argv),'stderr'=>''];}
$transport=file_get_contents(__DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/transport.php');
$transport=str_replace('function unmTransportRun(','function unusedCpuCompatibilityTransportRun(',$transport);
$transport=str_replace("__DIR__.'/zvol-path.php'",var_export(realpath(__DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/zvol-path.php'),true),$transport);
$fixture=tempnam(sys_get_temp_dir(),'unmotion-cpu-transport-');file_put_contents($fixture,$transport);
try {
    require $fixture;
    $payload=base64_encode('{"machine":"pc-q35-10.2"}');
    $argv=['/usr/local/sbin/unmotion-agent','cpu-compatibility',$payload];$paired=['id'=>'peer','hostId'=>'remote','pairingState'=>'paired'];
    $r=unmTransportAgent($argv,$paired,'key');
    cpuCheck(json_decode($r['stdout'],true)===$argv,'Paired CPU RPC dispatches only fixed agent argv.');
    cpuReject(fn()=>unmTransportAgent($argv,null,'key'),'Unpaired CPU queries are rejected.');
    cpuReject(fn()=>unmTransportAgent($argv,['pairingState'=>'pending'],'key'),'Incomplete reciprocal pairing is rejected.');
    cpuReject(fn()=>unmTransportAgent(array_merge($argv,['extra']),$paired,'key'),'Extra CPU RPC arguments are rejected.');
    foreach (['{"machine":"q35;reboot"}','{"machine":"q35","command":"id"}'] as $bad) cpuReject(fn()=>unmTransportAgent([$argv[0],$argv[1],base64_encode($bad)],$paired,'key'),'CPU payload cannot request arbitrary shell commands.');
} finally {unlink($fixture);}
echo "CPU discovery, masks, context, unknown states and validation: $checks assertions passed.\n";
