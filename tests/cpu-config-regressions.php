<?php
// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
declare(strict_types=1);
require_once __DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/lib.php';
$checks=0;
function cpuAssert(bool $ok,string $why): void {global $checks;$checks++;if(!$ok)throw new RuntimeException($why);}
function cpuReject(callable $f,string $message): void {try{$f();}catch(Throwable $e){cpuAssert(str_contains($e->getMessage(),$message),$e->getMessage().' expected '.$message);return;}throw new RuntimeException('Expected rejection: '.$message);}
$mask='<cpu mode="custom" match="exact" check="full"><model fallback="forbid">Test-v2</model><vendor>Intel</vendor><feature policy="require" name="lm"/><feature policy="require" name="sse2"/></cpu>';
$mask=unmCpuCompatibilityDefinition($mask);$hash=hash('sha256',$mask);$identity=str_repeat('a',64);
$a='11111111-1111-4111-8111-111111111111';$b='22222222-2222-4222-8222-222222222222';
function cpuFixture(string $uuid,string $name='Squid Proxy'): string {
    return '<domain type="kvm"><name>'.$name.'</name><uuid>'.$uuid.'</uuid><memory unit="MiB">512</memory><vcpu>2</vcpu><cputune><vcpupin vcpu="0" cpuset="2"/></cputune><os><type arch="x86_64" machine="pc-q35-9.2">hvm</type><nvram>/etc/libvirt/qemu/nvram/'.$uuid.'_VARS.fd</nvram></os><cpu mode="host-passthrough" migratable="on"><topology sockets="1" cores="2" threads="1"/><numa><cell id="0" cpus="0-1" memory="524288" unit="KiB"/></numa><cache mode="passthrough"/></cpu><devices><disk type="file" device="disk"><source file="/mnt/cache/domains/Squid Proxy/disk.raw"/></disk><interface type="bridge"><mac address="52:54:00:11:22:33"/></interface><tpm model="tpm-tis"><backend type="emulator" version="2.0"/></tpm></devices></domain>';
}
function cpuOutside(string $xml): string {$d=unmCpuCompatibilityXml($xml);$x=new DOMXPath($d);foreach($x->query('/domain/cpu') as $n)$n->parentNode->removeChild($n);return $d->C14N();}
$original=cpuFixture($a);$change=unmCpuConfigTransform($original,$mask);
cpuAssert(cpuOutside($original)===cpuOutside($change['xml']),'All non-CPU XML must be identical.');
$x=new DOMXPath(unmCpuCompatibilityXml($change['xml']));
cpuAssert($x->query('/domain/cpu/topology[@cores="2"]')->length===1,'Topology preserved.');
cpuAssert($x->query('/domain/cpu/numa/cell[@cpus="0-1"]')->length===1,'NUMA preserved.');
cpuAssert($x->query('/domain/cpu/cache')->length===0,'Host-passthrough cache removed.');
cpuAssert($x->evaluate('string(/domain/cpu/@mode)')==='custom','Exact custom mode applied.');
cpuAssert($x->evaluate('string(/domain/cpu/@check)')==='partial','Use libvirt startup checks, never check=none or premature full realization checks.');
cpuAssert($x->query('/domain/cpu/feature[@name="hypervisor" and @policy="require"]')->length===1,'Guest hypervisor bit is explicit.');
$hidden=unmCpuConfigTransform(str_replace('<cache mode="passthrough"/>','<feature policy="disable" name="hypervisor"/>',$original),$mask);
cpuAssert((new DOMXPath(unmCpuCompatibilityXml($hidden['xml'])))->query('/domain/cpu/feature[@name="hypervisor" and @policy="disable"]')->length===1,'Existing hidden hypervisor bit preserved.');
$latent=unmCpuConfigTransform($original,str_replace('</cpu>','<feature policy="require" name="vmx-ept"/></cpu>',$mask));
cpuAssert((new DOMXPath(unmCpuCompatibilityXml($latent['xml'])))->query('/domain/cpu/feature[@name="vmx-ept" and @policy="disable"]')->length===1,'Unused VMX subfeatures are disabled without enabling nested virtualization.');
$nested=unmCpuConfigTransform($original,str_replace('</cpu>','<feature policy="require" name="vmx"/><feature policy="require" name="vmx-ept"/></cpu>',$mask));
cpuAssert((new DOMXPath(unmCpuCompatibilityXml($nested['xml'])))->query('/domain/cpu/feature[@name="vmx-ept" and @policy="require"]')->length===1,'A model explicitly requiring VMX keeps its subfeatures.');
cpuAssert(unmCpuConfigTransform($change['xml'],$mask)['hash']===unmCpuConfigTransform($change['xml'],$mask)['targetHash'],'Idempotent transform.');
cpuReject(fn()=>unmCpuConfigTransform($original,str_replace('name="lm"','name="other"',$mask)),'64-bit');
cpuReject(fn()=>unmCpuConfigTransform(str_replace('pc-q35-9.2','microvm',$original),$mask),'Q35');
cpuReject(fn()=>unmCpuConfigTransform(str_replace('x86_64','i686',$original),$mask),'x86_64');
cpuReject(fn()=>unmCpuConfigTransform(str_replace('<cache mode="passthrough"/>','<maxphysaddr mode="passthrough"/>',$original),$mask),'Special CPU');
cpuReject(fn()=>unmCpuConfigTransform(str_replace('<devices>','<metadata><activation xmlns="urn:unmotion:recovery:1"/></metadata><devices>',$original),$mask),'Recovery-owned');
cpuReject(fn()=>unmCpuConfigTransform(str_replace('<devices>','<q:commandline xmlns:q="http://libvirt.org/schemas/domain/qemu/1.0"><q:arg value="-cpu"/></q:commandline><devices>',$original),$mask),'override');
cpuReject(fn()=>unmCpuConfigTransform('<!DOCTYPE domain [<!ENTITY x "bad">]>'.$original,$mask),'Invalid CPU');

$tmp=sys_get_temp_dir().'/unmotion-cpu-config-'.bin2hex(random_bytes(6));mkdir($tmp,0700);mkdir($tmp.'/locks',0700);
$domains=[$a=>$original,$b=>cpuFixture($b,'Another VM')];$calls=[];$managed=false;$failDefine='';$compareOk=true;$expandOk=true;$schemaOk=true;$guarded=[];$minimumBackups=2;
$view=['available'=>true,'cache'=>['identity'=>$identity],'modes'=>[['id'=>'Test-v2','status'=>'supported','cpuXml'=>$mask,'definitionHash'=>$hash]]];
$runner=function(array $args,?string $input,int $timeout)use(&$domains,&$calls,&$managed,&$failDefine,&$compareOk,&$expandOk,&$minimumBackups,$mask,$tmp){
    $calls[]=$args;$out='';$code=0;$error='';
    switch($args[1]){
        case 'list':$out=implode("\n",array_keys($domains));break;
        case 'dumpxml':$out=$domains[$args[2]];break;
        case 'dominfo':$out="Persistent: yes\nManaged save: ".($managed?'yes':'no')."\nState: running\n";break;
        case 'hypervisor-cpu-baseline':$out=$expandOk?$mask:str_replace('name="sse2"','name="different"',$mask);break;
        case 'hypervisor-cpu-compare':$code=$compareOk?0:1;$error=$compareOk?'':'CPU unsupported now';break;
        case 'define':
            cpuAssert(count(glob($tmp.'/backups/*/*.xml')?:[])===$minimumBackups,'Every original must be backed up before any define.');
            $x=new DOMXPath(unmCpuCompatibilityXml($input));$id=$x->evaluate('string(/domain/uuid)');
            if($id===$failDefine){$code=1;$error='Injected define failure';}else $domains[$id]=$input;
            break;
        default:throw new RuntimeException('Unexpected command '.json_encode($args));
    }
    return ['code'=>$code,'stdout'=>$out,'stderr'=>$error];
};
$service=new UnmCpuConfigService($runner,fn()=>$view,function($uuid)use(&$guarded){if(in_array($uuid,$guarded,true))throw new RuntimeException('Guarded VM');},$tmp.'/locks',$tmp.'/backups','test-host|boot',null,function($xml)use(&$schemaOk){return $schemaOk;});
try{
    $preview=$service->execute('Test-v2',$hash,$identity);
    cpuAssert($preview['eligible']===2&&$preview['applied']===0,'Preview must be nonmutating.');
    cpuAssert(!in_array('define',array_column($calls,1),true),'No definitions changed during preview.');
    cpuAssert(count(array_filter($calls,fn($c)=>$c[1]==='hypervisor-cpu-compare'))===2,'Fresh model and guest validation deduplicate exact machine versions.');
    cpuReject(fn()=>$service->execute('Test-v2',$hash,str_repeat('b',64)),'CPU results changed');
    cpuReject(fn()=>$service->execute('Test-v2',str_repeat('b',64),$identity),'no longer');
    cpuReject(fn()=>$service->execute('-bad',$hash,$identity),'Invalid CPU selection');
    $domains[$a]=str_replace('<vcpu>2','<vcpu>4',$original);
    cpuReject(fn()=>$service->execute('Test-v2',$hash,$identity,$preview['review']),'Nothing was changed');$domains[$a]=$original;
    $managed=true;cpuAssert($service->execute('Test-v2',$hash,$identity)['eligible']===0,'Saved memory states skipped.');$managed=false;
    $guarded=[$a];cpuAssert($service->execute('Test-v2',$hash,$identity)['eligible']===1,'Guarded VMs skipped.');$guarded=[];
    $compareOk=false;cpuAssert($service->execute('Test-v2',$hash,$identity)['eligible']===0,'Fresh compare overrides cached support.');$compareOk=true;
    $expandOk=false;cpuAssert($service->execute('Test-v2',$hash,$identity)['eligible']===0,'Machine-specific definition differences rejected.');$expandOk=true;
    $schemaOk=false;cpuAssert($service->execute('Test-v2',$hash,$identity)['eligible']===0,'Schema validation required.');$schemaOk=true;
    $lock=fopen($tmp.'/locks/unmotion-'.$a.'.lock','c');flock($lock,LOCK_EX);
    cpuAssert($service->execute('Test-v2',$hash,$identity)['eligible']===1,'VM worker locks respected.');flock($lock,LOCK_UN);fclose($lock);
    $lock=fopen($tmp.'/locks/unmotion.lock','c');flock($lock,LOCK_EX);cpuReject(fn()=>$service->execute('Test-v2',$hash,$identity),'busy');flock($lock,LOCK_UN);fclose($lock);
    $failDefine=$b;$partial=$service->execute('Test-v2',$hash,$identity,$preview['review']);
    cpuAssert(!$partial['complete']&&$partial['applied']===1,'Partial application must be explicit.');
    cpuAssert($partial['vms'][1]['status']==='needs_review','Failed VM reported individually.');
    cpuAssert(file_get_contents($partial['backupDirectory'].'/'.$a.'.xml')===$original,'Original XML retained exactly.');
    cpuAssert(json_decode(file_get_contents($partial['backupDirectory'].'/result.json'),true)===$partial,'Durable result matches partial response.');
    cpuAssert(cpuOutside($domains[$a])===cpuOutside($original),'Applying changes only CPU.');
    cpuAssert(!array_intersect(['start','shutdown','destroy','reboot','managedsave-remove','autostart'],array_column($calls,1)),'No guest lifecycle/autostart commands allowed.');
    cpuReject(fn()=>$service->execute('Test-v2',$hash,$identity,$preview['review']),'Nothing was changed');
    $domains=[$a=>$original,$b=>cpuFixture($b,'Another VM')];$failDefine='';$minimumBackups=4;
    $done=$service->execute('Test-v2',$hash,$identity,$preview['review']);
    cpuAssert($done['complete']&&$done['applied']===2,'All eligible definitions apply successfully.');
    $again=$service->execute('Test-v2',$hash,$identity);cpuAssert($again['eligible']===0&&count(array_filter($again['vms'],fn($r)=>$r['status']==='unchanged'))===2,'Already matching definitions are unchanged.');
    $aliasMask=str_replace('Test-v2','Canonical-v1',$mask);$aliasHash=hash('sha256',$aliasMask);
    $aliasView=$view;$aliasView['modes'][0]['cpuXml']=$aliasMask;$aliasView['modes'][0]['definitionHash']=$aliasHash;
    $aliasRun=function($args,$input,$timeout)use($runner,$aliasMask){return $args[1]==='hypervisor-cpu-baseline'?['code'=>0,'stdout'=>$aliasMask,'stderr'=>'']:$runner($args,$input,$timeout);};
    $aliasService=new UnmCpuConfigService($aliasRun,fn()=>$aliasView,fn($uuid)=>null,$tmp.'/locks',$tmp.'/backups','test-host|boot',null,fn($xml)=>true);
    $aliasPlan=$aliasService->execute('Test-v2',$aliasHash,$identity);
    cpuAssert($aliasPlan['eligible']===0,'Persist selected versioned name, not an equivalent internal baseline name.');
    mkdir($tmp.'/policies');mkdir($tmp.'/policies/seeds');mkdir($tmp.'/policies/seeds/test');
    file_put_contents($tmp.'/policies/seeds/test/seed.json',json_encode(['vmUuid'=>$a,'state'=>'READY']));
    cpuReject(fn()=>unmCpuConfigGuard($tmp.'/policies',$a),'Prepared');
    unmCpuConfigGuard($tmp.'/policies',$b);cpuAssert(true,'Unrelated VM work does not block eligibility.');
    echo $checks." CPU configuration assertions passed.\n";
}finally{
    $items=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($items as $item)$item->isDir()?rmdir($item->getPathname()):unlink($item->getPathname());rmdir($tmp);
}
