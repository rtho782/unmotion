<?php
// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
declare(strict_types=1);
require_once __DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/cpu-editor.php';
$checks=0;
function defAssert(bool $ok,string $why): void {global $checks;$checks++;if(!$ok)throw new RuntimeException($why);}
function defReject(callable $f,string $message): void {try{$f();}catch(Throwable $e){defAssert(str_contains($e->getMessage(),$message),$e->getMessage());return;}throw new RuntimeException('Expected '.$message);}
$native=new class {public bool $exists=false;function domain_generate_uuid(){return '11111111-1111-4111-8111-111111111111';}function domain_get_domain_by_uuid($uuid){return $this->exists;}};
$identity=[];defAssert(unmCpuEditorRequestUuid($identity,true,false,$native)===$identity['uuid'],'Template creation generates and binds a fresh native UUID.');
$native->exists=true;defReject(fn()=>unmCpuEditorRequestUuid($identity,true,false,$native),'already exists');
$empty=[];defReject(fn()=>unmCpuEditorRequestUuid($empty,false,false,$native),'Invalid VM identity');
$cpu=unmCpuCompatibilityDefinition('<cpu mode="custom" match="exact" check="full"><model fallback="forbid">Equivalent-v1</model><vendor>Intel</vendor><feature policy="require" name="lm"/></cpu>');
$selection=['mode'=>'Skylake-Server-v5','definition_hash'=>hash('sha256',$cpu),'cache_identity'=>str_repeat('a',64)];
$view=['available'=>true,'cache'=>['identity'=>$selection['cache_identity']],'modes'=>[['id'=>$selection['mode'],'status'=>'supported','definitionHash'=>$selection['definition_hash'],'cpuXml'=>$cpu]]];
$r=unmCpuDefaultResolve($selection,$view);
$profile='unmotion:profile:'.$selection['mode'].':'.$selection['definition_hash'];
defAssert(unmCpuEditorProfile($profile,$view)['mode']===$selection['mode'],'Saved templates retain their explicit named profile independently of the current default.');
defReject(fn()=>unmCpuEditorProfile(str_replace($selection['definition_hash'],str_repeat('c',64),$profile),$view),'not validated');
defReject(fn()=>unmCpuEditorProfile('unmotion:profile:bad/../mode:'.str_repeat('a',64),$view),'Invalid saved');
$vm='<domain><cpu mode="custom"><model>Skylake-Server-v5</model></cpu></domain>';
defAssert(unmCpuConfigReadiness($vm,$vm)['status']==='model-active','Matching named CPU is display-only model evidence.');
defAssert(unmCpuConfigReadiness($vm,str_replace('Skylake-Server-v5','Broadwell-v4',$vm))['status']==='different','A different active mode is clearly pending.');
defAssert(unmCpuConfigReadiness($vm,null)['status']==='next-boot','Stopped guest has next-boot CPU, not active migration approval.');
$relative=str_replace('</domain>','<metadata><vmtemplate xmlns="unraid" name="Linux"/></metadata></domain>',$vm);
defAssert(unmXmlCanonical(unmCpuCompatibilityXml($vm))===unmCpuCompatibilityXml($vm)->C14N(),'Existing valid C14N hashes remain unchanged.');
defAssert(unmLiveXmlHash($relative)!==unmLiveXmlHash(str_replace('Skylake','Broadwell',$relative)),'Relative Unraid namespace cannot collapse distinct CPU definitions to an empty hash.');
defAssert(unmCpuConfigHash($relative)!==unmCpuConfigHash(str_replace('name="Linux"','name="Other"',$relative)),'Relative namespace hashing retains metadata differences.');
$relativeCpu=(new DOMXPath(unmCpuCompatibilityXml($relative)))->query('/domain/cpu')->item(0);
defAssert(unmXmlCanonical($relativeCpu)===unmXmlCanonical((new DOMXPath(unmCpuCompatibilityXml($vm)))->query('/domain/cpu')->item(0)),'A CPU subtree is independent of unrelated relative metadata namespaces.');
defAssert(str_contains($r['cpuXml'],'Skylake-Server-v5'),'The user-selected named revision is pinned.');
defReject(fn()=>unmCpuDefaultResolve($selection,array_replace($view,['available'=>false])),'cache changed');
defReject(fn()=>unmCpuDefaultResolve(array_replace($selection,['cache_identity'=>str_repeat('b',64)]),$view),'cache changed');
defReject(fn()=>unmCpuDefaultResolve(array_replace($selection,['definition_hash'=>str_repeat('b',64)]),$view),'not validated');
defReject(fn()=>unmCpuDefaultResolve(array_replace($selection,['mode'=>'x; id']),$view),'Invalid default');
$xml='<domain><cpu mode="custom" match="exact" check="partial"><model fallback="forbid">Skylake-Server-v5</model><topology sockets="1" cores="2" threads="2"/><feature policy="require" name="vmx"/><feature policy="disable" name="vmx-ept"/></cpu></domain>';
$d=unmCpuCompatibilityXml($xml);$node=(new DOMXPath($d))->query('/domain/cpu')->item(0);$token='unmotion:keep:'.hash('sha256',unmXmlCanonical($node));
defAssert(unmCpuEditorSelect($token,$xml)===$d->saveXML($node),'Keep existing preserves the entire CPU definition.');
defReject(fn()=>unmCpuEditorSelect($token,str_replace('cores="2"','cores="3"',$xml)),'changed after');
defReject(fn()=>unmCpuEditorSelect($token,null),'unavailable');
$_SERVER['REQUEST_METHOD']='POST';$GLOBALS['unmCpuEditorPrepared']=['mode'=>$token,'uuid'=>'11111111-1111-4111-8111-111111111111','cpu'=>$d->saveXML($node)];
$config=['domain'=>['cpumode'=>$token,'uuid'=>$GLOBALS['unmCpuEditorPrepared']['uuid']]];
$fragment='<cpu><topology sockets="1" cores="4" threads="1"/></cpu><vcpu placement="static">4</vcpu><cputune><vcpupin vcpu="0" cpuset="3"/></cputune>';
$next=unmCpuCompatibilityXml('<root>'.unmCpuEditorFragment($fragment,$config).'</root>');$xp=new DOMXPath($next);
defAssert($xp->evaluate('string(/root/cpu/topology/@threads)')==='2','Unchanged vCPU count preserves existing SMT topology.');
defAssert($xp->query('/root/cpu/feature[@name="vmx" and @policy="require"]')->length===1,'Nested VMX retained.');
defAssert($xp->query('/root/cpu/feature[@name="vmx-ept" and @policy="disable"]')->length===1,'Explicit subfeature opt-out retained.');
defAssert($xp->evaluate('string(/root/cputune/vcpupin/@cpuset)')==='3','Pinning retained.');
$next=unmCpuCompatibilityXml('<root>'.unmCpuEditorFragment(str_replace('>4<','>6<',str_replace('cores="4"','cores="6"',$fragment)),$config).'</root>');
defAssert((new DOMXPath($next))->evaluate('string(/root/cpu/topology/@cores)')==='6','Explicit vCPU change updates topology.');
defReject(fn()=>unmCpuEditorFragment($fragment,['domain'=>['cpumode'=>$token,'uuid'=>'changed']]),'not validated');
$options=unmCpuEditorOptions($config,false,$xml,['custom'=>'QEMU64']);
defAssert($config['domain']['cpumode']===$token&&isset($options[$token]),'Existing custom selection does not follow the default.');
foreach(unmCpuEditorPatches() as $file=>$spec){
    defReject(fn()=>unmCpuEditorPatch('unknown source',$spec),'Unreviewed');
    $root=getenv('UNM_VM_EDITOR_SOURCE');if($root){$original=(string)file_get_contents($root.'/'.$file);$patched=unmCpuEditorPatch($original,$spec);defAssert($patched!==$original,'Native adapter changes '.$file);defReject(fn()=>unmCpuEditorPatch($patched,$spec),'Unreviewed');}
}
echo "CPU default/editor regressions: $checks assertions passed.\n";
