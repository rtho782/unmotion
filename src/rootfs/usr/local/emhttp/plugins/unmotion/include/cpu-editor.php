<?php
// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
declare(strict_types=1);
require_once __DIR__.'/lib.php';

function unmCpuEditorToken(array $r): string {return 'unmotion:'.$r['mode'].':'.$r['definition_hash'];}
function unmCpuEditorExisting(string $uuid,$lv): ?string {
    if(!preg_match('/\A[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\z/D',$uuid))throw new RuntimeException('Invalid VM identity.');
    $dom=$lv->domain_get_domain_by_uuid($uuid);if(!$dom)return null;
    $r=unmRun(['virsh','dumpxml',$uuid,'--inactive','--security-info'],null,15);
    if($r['code']!==0)throw new RuntimeException('Cannot read the persistent CPU definition.');
    return $r['stdout'];
}
function unmCpuEditorRequestUuid(array &$domain,bool $creating,bool $template,$lv): string {
    $uuid=strtolower((string)($domain['uuid']??''));
    // Native user-templates intentionally omit UUIDs; bind a newly generated
    // identity before validating the CPU and before config_to_xml runs.
    if($uuid===''&&($creating||$template))$uuid=strtolower((string)$lv->domain_generate_uuid());
    if(!preg_match('/\A[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\z/D',$uuid))throw new RuntimeException('Invalid VM identity.');
    if($creating&&$lv->domain_get_domain_by_uuid($uuid))throw new RuntimeException('A VM with this identity already exists. Reload the creation form.');
    $domain['uuid']=$uuid;return $uuid;
}
function unmCpuEditorRequest(array &$defaults,array $overrides,$lv): void {
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){
        $template=(string)($overrides['domain']['cpumode']??'');
        if(str_starts_with($template,'unmotion:saved:')){
            try{unmCpuEditorSaved($template);$defaults['domain']['cpumode']=$template;}
            catch(Throwable $e){$defaults['domain']['cpumode']='unmotion:unavailable';}return;
        }
        if(str_starts_with($template,'unmotion:')&&!str_starts_with($template,'unmotion:keep:')){
            $pinned=str_starts_with($template,'unmotion:profile:')?$template:'unmotion:profile:'.substr($template,9);
            try{unmCpuEditorProfile($pinned,unmCpuCompatibilityLocalOverview(false));$defaults['domain']['cpumode']=$pinned;}
            catch(Throwable $e){$defaults['domain']['cpumode']='unmotion:unavailable';}
            return;
        }
        try{$r=unmCpuDefaultRead();if($r&&!isset($overrides['domain']['cpumode']))$defaults['domain']['cpumode']=unmCpuEditorStatus()['available']?unmCpuEditorToken($r):'unmotion:unavailable';}
        catch(Throwable $e){if(!isset($overrides['domain']['cpumode']))$defaults['domain']['cpumode']='unmotion:unavailable';}
        return;
    }
    if(isset($_POST['xmldesc'])||!isset($_POST['createvm'])&&!isset($_POST['updatevm'])&&!isset($_POST['createvmtemplate']))return;
    $mode=(string)($_POST['domain']['cpumode']??'');if(!str_starts_with($mode,'unmotion:'))return;
    try{
        if(!unmCpuEditorStatus()['available'])throw new RuntimeException('The native CPU editor adapter is not available.');
        $uuid=unmCpuEditorRequestUuid($_POST['domain'],isset($_POST['createvm']),isset($_POST['createvmtemplate']),$lv);$existing=unmCpuEditorExisting($uuid,$lv);
        if($existing!==null)unmCpuConfigGuard(UNM_BOOT_DIR,$uuid);
        $cpu=unmCpuEditorSelect($mode,$existing);$machine=(string)($_POST['domain']['machine']??'');
        if(($_POST['domain']['arch']??'')!=='x86_64')throw new RuntimeException('The default CPU requires x86_64.');
        unmCpuEditorCompare($cpu,$machine);
        $testCpu=unmCpuCompatibilityXml($cpu);$tx=new DOMXPath($testCpu);$top=$tx->query('/cpu/topology')->item(0);
        if($top&&$tx->query('/cpu/numa')->length){$pins=$_POST['domain']['vcpu']??[];$wanted=is_array($pins)&&count($pins)?count($pins):(int)($_POST['domain']['vcpus']??0);$count=(int)$top->getAttribute('sockets')*(int)($top->getAttribute('dies')?:1)*(int)($top->getAttribute('clusters')?:1)*(int)$top->getAttribute('cores')*(int)$top->getAttribute('threads');if($count!==$wanted)throw new RuntimeException('Changing a NUMA CPU topology requires XML review.');}
        $cx=new DOMXPath(unmCpuCompatibilityXml($cpu));foreach(['vmx','svm'] as $feature)if($cx->query('/cpu/feature[@name="'.$feature.'" and (@policy="require" or @policy="force")]')->length&&!unmCpuNestedEnabled($feature))throw new RuntimeException('The template requires KVM nested '.$feature.', which is disabled.');
        if(isset($_POST['createvmtemplate'])){
            $mode=unmCpuEditorStore($cpu);$_POST['domain']['cpumode']=$mode;unset($_POST['domain']['uuid']);$uuid='';
        }
        $GLOBALS['unmCpuEditorPrepared']=['mode'=>$mode,'uuid'=>$uuid,'cpu'=>$cpu];
    }catch(Throwable $e){header('Content-Type: application/json');echo json_encode(['error'=>$e->getMessage()]);exit;}
}
function unmCpuEditorStore(string $cpu,?string $directory=null): string {
    $d=unmCpuCompatibilityXml($cpu);if($d->documentElement->tagName!=='cpu'||$d->documentElement->namespaceURI||strlen($cpu)>262144)throw new RuntimeException('Invalid saved CPU profile.');
    $hash=hash('sha256',unmXmlCanonical($d));$directory??=UNM_BOOT_DIR.'/cpu-editor-profiles';unmLivePrivateDir($directory);$path=$directory.'/'.$hash.'.json';
    if(is_link($path))throw new RuntimeException('Redirected saved CPU profile.');
    if(!is_file($path)){
        if(count(glob($directory.'/*.json')?:[])>=128)throw new RuntimeException('Saved CPU profile limit reached.');
        unmAtomicJson($path,['schemaVersion'=>1,'cpuXml'=>$cpu]);unmLiveCommand(['sync','-f',$path]);
    }
    return 'unmotion:saved:'.$hash;
}
function unmCpuEditorSaved(string $token,?string $directory=null): string {
    if(!preg_match('/\Aunmotion:saved:([a-f0-9]{64})\z/D',$token,$m))throw new RuntimeException('Invalid saved CPU identity.');
    $path=($directory??UNM_BOOT_DIR.'/cpu-editor-profiles').'/'.$m[1].'.json';
    if(is_link($path)||!is_file($path)||filesize($path)>524288)throw new RuntimeException('The saved template CPU profile is unavailable.');
    $r=unmLoadJson($path);$d=unmCpuCompatibilityXml((string)($r['cpuXml']??''));
    if(($r['schemaVersion']??0)!==1||$d->documentElement->tagName!=='cpu'||$d->documentElement->namespaceURI||!hash_equals($m[1],hash('sha256',unmXmlCanonical($d))))throw new RuntimeException('The saved CPU profile changed.');
    return $d->saveXML($d->documentElement);
}
function unmCpuEditorProfile(string $mode,array $view): array {
    if(!preg_match('/\Aunmotion:profile:([A-Za-z0-9][A-Za-z0-9_.+-]{0,191}):([a-f0-9]{64})\z/D',$mode,$m))throw new RuntimeException('Invalid saved template CPU profile.');
    return unmCpuDefaultResolve(['mode'=>$m[1],'definition_hash'=>$m[2],'cache_identity'=>$view['cache']['identity']??''],$view);
}
function unmCpuEditorSelect(string $mode,?string $existing): string {
    if(str_starts_with($mode,'unmotion:saved:')){
        $cpu=unmCpuEditorSaved($mode);if($existing===null)return $cpu;
        $d=unmCpuCompatibilityXml($cpu);$x=new DOMXPath($d);foreach(iterator_to_array($x->query('/cpu/*[not(self::model or self::vendor or self::feature)]')) as $n)$n->parentNode->removeChild($n);
        return unmCpuConfigService()->prepareVm($existing,$d->saveXML())['cpuXml'];
    }
    if(str_starts_with($mode,'unmotion:keep:')){
        if($existing===null)throw new RuntimeException('The existing VM CPU is unavailable.');
        $d=unmCpuCompatibilityXml($existing);$xp=new DOMXPath($d);$nodes=$xp->query('/domain/cpu');
        if($nodes->length!==1)throw new RuntimeException('The existing CPU is ambiguous.');
        $cpu=$d->saveXML($nodes->item(0));if(!hash_equals('unmotion:keep:'.hash('sha256',unmXmlCanonical($nodes->item(0))),$mode))throw new RuntimeException('The VM CPU changed after the form was opened. Reload the editor.');
        return $cpu;
    }
    if(str_starts_with($mode,'unmotion:profile:'))$r=unmCpuEditorProfile($mode,unmCpuCompatibilityLocalOverview(false));
    else{$r=unmCpuDefaultRead();if(!$r||!hash_equals(unmCpuEditorToken($r),$mode))throw new RuntimeException('The default CPU changed after the form was opened. Reload the editor.');}
    // Replacing an existing CPU is an explicit dropdown choice, not a side effect of a default change.
    if($existing!==null){$change=unmCpuConfigService()->prepareVm($existing,$r['cpuXml']);return $change['cpuXml'];}
    return unmCpuConfigGuestDefinition($r['cpuXml'],new DOMXPath(unmCpuCompatibilityXml('<domain/>')));
}
function unmCpuEditorOptions(array &$config,bool $new,string $xml,array $options): array {
    if(!$new){
        $d=unmCpuCompatibilityXml($xml);$xp=new DOMXPath($d);$cpu=$xp->query('/domain/cpu')->item(0);
        if($cpu&&$cpu->getAttribute('mode')==='custom'){
            $key='unmotion:keep:'.hash('sha256',unmXmlCanonical($cpu));$options[$key]='Keep existing custom CPU ('.$xp->evaluate('string(/domain/cpu/model)').')';$config['domain']['cpumode']=$key;
        }
    }
    try{$r=unmCpuDefaultRead();if($r)$options[unmCpuEditorToken($r)]='unMotion default — '.$r['mode'];}catch(Throwable $e){/* Invalid defaults must not become silently selectable. */}
    $mode=(string)($config['domain']['cpumode']??'');if(str_starts_with($mode,'unmotion:profile:')){$r=unmCpuEditorProfile($mode,unmCpuCompatibilityLocalOverview(false));$options[$mode]='Template CPU — '.$r['mode'];}
    if(str_starts_with($mode,'unmotion:saved:')){$d=unmCpuCompatibilityXml(unmCpuEditorSaved($mode));$options[$mode]='Template CPU — '.(new DOMXPath($d))->evaluate('string(/cpu/model)');}
    if(($config['domain']['cpumode']??'')==='unmotion:unavailable')$options['unmotion:unavailable']='unMotion default unavailable — review CPU compatibility';
    return $options;
}
function unmCpuEditorFragment(string $fragment,array $config): string {
    $mode=(string)($config['domain']['cpumode']??'');if(!str_starts_with($mode,'unmotion:'))return $fragment;
    $prepared=$GLOBALS['unmCpuEditorPrepared']??null;
    if(($_SERVER['REQUEST_METHOD']??'')==='POST'){
        if(!$prepared||$prepared['mode']!==$mode||$prepared['uuid']!==strtolower((string)($config['domain']['uuid']??'')))throw new RuntimeException('The CPU selection was not validated before saving.');
        $cpu=$prepared['cpu'];
    }elseif($mode==='unmotion:unavailable')return $fragment;
    else $cpu=unmCpuEditorSelect($mode,null);
    $d=unmCpuCompatibilityXml('<root>'.$fragment.'</root>');$xp=new DOMXPath($d);$old=$xp->query('/root/cpu')->item(0);$replacement=unmCpuCompatibilityXml($cpu)->documentElement;
    if(!$old)throw new RuntimeException('Native CPU fragment changed.');
    $next=$d->importNode($replacement,true);$oldXp=new DOMXPath($d);$topology=$oldXp->query('/root/cpu/topology')->item(0);
    // Preserve the existing topology unless the user explicitly changed the vCPU count.
    $nx=new DOMXPath($next->ownerDocument);$current=$nx->query('topology',$next)->item(0);
    $count=static fn($n)=>(int)$n->getAttribute('sockets')*(int)($n->getAttribute('dies')?:1)*(int)($n->getAttribute('clusters')?:1)*(int)$n->getAttribute('cores')*(int)$n->getAttribute('threads');
    $vcpus=(int)$xp->evaluate('string(/root/vcpu)');
    if(!$current||$count($current)!==$vcpus){if($nx->query('numa',$next)->length)throw new RuntimeException('Changing a NUMA CPU topology requires XML review.');if($current)$next->removeChild($current);if($topology)$next->appendChild($topology->cloneNode(true));}
    if(!str_starts_with($mode,'unmotion:keep:')&&!str_starts_with($mode,'unmotion:saved:')){$next->setAttribute('mode','custom');$next->setAttribute('match','exact');$next->setAttribute('check','partial');}
    $d->documentElement->replaceChild($next,$old);
    $out='';foreach($d->documentElement->childNodes as $child)$out.=$d->saveXML($child);return $out;
}
function unmCpuEditorFinalXml(string $xml,array $config): string {
    if(!str_starts_with((string)($config['domain']['cpumode']??''),'unmotion:'))return $xml;
    $d=unmCpuCompatibilityXml($xml);$xp=new DOMXPath($d);$cpu=$xp->query('/domain/cpu')->item(0);
    if(!$cpu)throw new RuntimeException('Missing native CPU after editor merge.');
    $fragment=unmCpuEditorFragment($d->saveXML($cpu).$d->saveXML($xp->query('/domain/vcpu')->item(0)),$config);
    $wanted=unmCpuCompatibilityXml('<root>'.$fragment.'</root>');$node=(new DOMXPath($wanted))->query('/root/cpu')->item(0);
    $d->documentElement->replaceChild($d->importNode($node,true),$cpu);return $d->saveXML();
}
