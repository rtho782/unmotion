<?php
// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
declare(strict_types=1);
require_once __DIR__.'/inventory-cache.php';

/** CPU observations only: these never authorize migration or change a VM. */
function unmCpuCompatibilityRequest(array $request): string {
    if (array_diff(array_keys($request), ['machine'])) throw new InvalidArgumentException('Unknown CPU compatibility option.');
    $machine=$request['machine']??'q35';
    if (!is_string($machine) || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.+-]{0,79}\z/D',$machine)) throw new InvalidArgumentException('Invalid machine type.');
    return $machine;
}

/** Scope only, not proof that an exact version is available on a destination. */
function unmCpuCompatibilityMachineFamily(string $machine): string {
    if($machine==='q35'||preg_match('/\Apc-q35-[0-9]+\.[0-9]+(?:\.[0-9]+)?\z/D',$machine))return 'q35';
    if($machine==='pc'||preg_match('/\Apc-(?:i440fx-)?[0-9]+\.[0-9]+(?:\.[0-9]+)?\z/D',$machine))return 'i440fx';
    return '';
}

/** Saved definitions only: no VM/storage/guest-agent or CPU capability probes. */
function unmCpuCompatibilityMachines(string $directory='/etc/libvirt/qemu'): array {
    $machines=['q35'=>['id'=>'q35','vms'=>[]],'pc'=>['id'=>'pc','vms'=>[]]];
    $skipped=0;$bytes=0;$unsupported=[];
    if(is_link($directory)||!is_dir($directory))return ['machines'=>array_values($machines),'unsupported'=>[],'complete'=>false,'error'=>'Saved VM definitions are unavailable. CPU host comparison is still available.'];
    $files=glob($directory.'/*.xml')?:[];
    if(count($files)>1024){$skipped=count($files)-1024;$files=array_slice($files,0,1024);}
    foreach($files as $file){
        try{
            $size=@filesize($file);
            if(is_link($file)||!is_file($file)||$size===false||$size>2097152||($bytes+=$size)>16777216)throw new RuntimeException('Unreadable or oversized VM definition.');
            $xml=@file_get_contents($file);if($xml===false)throw new RuntimeException('Unreadable VM definition.');
            $document=unmCpuCompatibilityXml($xml);$xpath=new DOMXPath($document);
            if($document->documentElement->tagName!=='domain'||$document->documentElement->namespaceURI)throw new RuntimeException('Invalid VM definition.');
            $arch=$xpath->evaluate('string(/domain/os/type/@arch)');
            $machine=unmCpuCompatibilityRequest(['machine'=>$xpath->evaluate('string(/domain/os/type/@machine)')]);
            $name=trim($xpath->evaluate('string(/domain/name)'));
            if($name==='')throw new RuntimeException('VM name is unavailable.');
            preg_match('/\A.{0,80}/us',$name,$displayName);
            if($document->documentElement->getAttribute('type')!=='kvm'||($arch!==''&&$arch!=='x86_64')){
                $unsupported[]=['name'=>$displayName[0],'machine'=>$machine,'reason'=>'CPU compatibility and planned live migration support x86_64 KVM only.'];continue;
            }
            if(unmCpuCompatibilityMachineFamily($machine)==='')$unsupported[]=['name'=>$displayName[0],'machine'=>$machine,'reason'=>'This machine family is outside Q35/i440fx CPU compatibility and planned live-migration support.'];
            if(!isset($machines[$machine]))$machines[$machine]=['id'=>$machine,'vms'=>[]];
            $machines[$machine]['vms'][]=$displayName[0];
        }catch(Throwable $e){++$skipped;}
    }
    $defaults=[$machines['q35'],$machines['pc']];unset($machines['q35'],$machines['pc']);
    uksort($machines,static fn($a,$b)=>strnatcmp($b,$a));
    return ['machines'=>array_merge($defaults,array_values($machines)),'unsupported'=>$unsupported,'complete'=>$skipped===0,'error'=>$skipped?'Some saved VM definitions could not be inspected. CPU host comparison is still available.':''];
}

function unmCpuCompatibilityVmScope(string $directory='/etc/libvirt/qemu'): array {
    $inventory=unmCpuCompatibilityMachines($directory);
    return ['supportedFamilies'=>['Q35','i440fx'],'unsupportedVms'=>$inventory['unsupported'],'complete'=>$inventory['complete'],'error'=>$inventory['error']];
}

function unmCpuCompatibilityXml(string $xml): DOMDocument {
    if (strlen($xml)>2097152 || preg_match('/<!DOCTYPE|<!ENTITY/i',$xml)) throw new RuntimeException('Invalid CPU capability XML.');
    $previous=libxml_use_internal_errors(true);
    try {
        $document=new DOMDocument();
        if (!$document->loadXML($xml,LIBXML_NONET|LIBXML_NOBLANKS) || !$document->documentElement) throw new RuntimeException('CPU capability XML is unavailable or malformed.');
        return $document;
    } finally {libxml_clear_errors();libxml_use_internal_errors($previous);}
}

/** Rebuild a narrow, deterministic CPU definition; never accept arbitrary domain XML. */
function unmCpuCompatibilityDefinition(string $xml): string {
    $root=unmCpuCompatibilityXml($xml)->documentElement;
    if ($root->tagName!=='cpu' || $root->namespaceURI || $root->getAttribute('mode')!=='custom' || $root->getAttribute('match')!=='exact') throw new RuntimeException('CPU baseline is not an exact custom model.');
    foreach ($root->attributes as $attribute) if (!in_array($attribute->name,['mode','match','check'],true)) throw new RuntimeException('Unsupported CPU baseline attribute.');
    $model='';$vendor='';$features=[];
    foreach ($root->childNodes as $node) {
        if (!$node instanceof DOMElement || $node->namespaceURI || $node->childElementCount) throw new RuntimeException('Unsupported CPU baseline content.');
        if ($node->tagName==='model' && $model==='') {
            if ($node->getAttribute('fallback')!=='forbid' || $node->attributes->length!==1) throw new RuntimeException('CPU model fallback must be forbidden.');
            $model=trim($node->textContent);
            if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.+-]{0,95}\z/D',$model)) throw new RuntimeException('Invalid CPU model.');
        } elseif ($node->tagName==='vendor' && $vendor==='') {
            $vendor=trim($node->textContent);
            if ($node->attributes->length || !preg_match('/\A[A-Za-z0-9_-]{1,32}\z/D',$vendor)) throw new RuntimeException('Invalid CPU vendor.');
        } elseif ($node->tagName==='feature') {
            $name=$node->getAttribute('name');$policy=$node->getAttribute('policy');
            if ($node->attributes->length!==2 || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.+-]{0,95}\z/D',$name) || !in_array($policy,['require','disable','forbid'],true) || isset($features[$name]) || count($features)>=512) throw new RuntimeException('Invalid CPU feature mask.');
            if ($name==='invtsc' && $policy==='require') throw new RuntimeException('CPU baseline contains a non-migratable clock feature.');
            $features[$name]=$policy;
        } else throw new RuntimeException('Unsupported CPU baseline element.');
    }
    if ($model==='' || !$features) throw new RuntimeException('CPU baseline is not expanded.');
    ksort($features,SORT_STRING);
    $result='<cpu mode="custom" match="exact" check="full"><model fallback="forbid">'.$model.'</model>';
    if ($vendor!=='') $result.='<vendor>'.$vendor.'</vendor>';
    foreach ($features as $name=>$policy) $result.='<feature policy="'.$policy.'" name="'.$name.'"/>';
    return $result.'</cpu>';
}

function unmCpuCompatibilityInspect(array $request,callable $run,string $hostId,string $hostname): array {
    $machine=unmCpuCompatibilityRequest($request);$started=microtime(true);
    $report=['schemaVersion'=>2,'hostId'=>$hostId,'hostname'=>$hostname,'observedAt'=>gmdate(DATE_ATOM),'displayOnly'=>true,'available'=>false,'complete'=>false,'context'=>['architecture'=>'x86_64','virtType'=>'kvm','machine'=>$machine],'modes'=>[],'error'=>''];
    try {
        $result=$run(['virsh','domcapabilities','--virttype','kvm','--arch','x86_64','--machine',$machine],null,10);
        if ($result['code']!==0) throw new RuntimeException('VM Manager CPU capabilities are unavailable: '.substr(trim($result['stderr']),0,500));
        $document=unmCpuCompatibilityXml($result['stdout']);$xpath=new DOMXPath($document);
        if ($document->documentElement->tagName!=='domainCapabilities' || $xpath->evaluate('string(/domainCapabilities/domain)')!=='kvm' || $xpath->evaluate('string(/domainCapabilities/arch)')!=='x86_64') throw new RuntimeException('Expected x86_64 KVM capabilities.');
        $resolved=$xpath->evaluate('string(/domainCapabilities/machine)');unmCpuCompatibilityRequest(['machine'=>$resolved]);
        $report['context']['machine']=$resolved;
        $report['context']['emulator']=$xpath->evaluate('string(/domainCapabilities/path)');
        $vendor=$xpath->evaluate('string(/domainCapabilities/cpu/mode[@name="host-model"]/vendor)');
        if (!preg_match('/\A[A-Za-z0-9_-]{1,32}\z/D',$vendor)) throw new RuntimeException('The host CPU vendor is unavailable.');
        $report['context']['vendor']=$vendor;
        $nodes=$xpath->query('/domainCapabilities/cpu/mode[@name="custom" and @supported="yes"]/model');
        if (!$nodes || !$nodes->length || $nodes->length>1024) throw new RuntimeException('Named CPU capabilities are unavailable.');
        $models=[];
        foreach ($nodes as $node) {
            $name=trim($node->textContent);
            // Versioned definitions avoid host-dependent aliases and include Intel/AMD generations.
            if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.+-]{0,80}-v[1-9][0-9]*\z/D',$name)) $models[$name]=['usable'=>$node->getAttribute('usable'),'deprecated'=>$node->getAttribute('deprecated')==='yes'];
        }
        if (!$models) throw new RuntimeException('This hypervisor reports no versioned CPU compatibility levels.');
        ksort($models,SORT_NATURAL);$report['available']=true;$report['complete']=true;
        foreach ($models as $name=>$info) {
            $mode=['id'=>$name,'status'=>'unknown','deprecated'=>$info['deprecated'],'reason'=>'Libvirt has not confirmed this model is usable.'];
            if ($info['usable']==='no') {$mode['status']='unsupported';$mode['reason']='This CPU model requires features unavailable on this host.';}
            elseif ($info['usable']==='yes' && microtime(true)-$started<300) {
                try {
                    $seed='<cpu mode="custom" match="exact"><model fallback="forbid">'.$name.'</model><vendor>'.$vendor.'</vendor></cpu>';
                    $options=['--virttype','kvm','--arch','x86_64','--machine',$resolved];
                    $baseline=$run(array_merge(['virsh','hypervisor-cpu-baseline','/dev/stdin','--features','--migratable'],$options),$seed,5);
                    if ($baseline['code']!==0) throw new RuntimeException('CPU expansion failed: '.substr(trim($baseline['stderr']),0,300));
                    $definition=unmCpuCompatibilityDefinition($baseline['stdout']);
                    $check=$run(array_merge(['virsh','hypervisor-cpu-compare','/dev/stdin','--error'],$options),$definition,5);
                    if ($check['code']!==0) throw new RuntimeException('CPU validation failed: '.substr(trim($check['stderr']),0,300));
                    $mode['status']='supported';$mode['reason']='Expanded migratable definition validated by libvirt.';
                    $mode['cpuXml']=$definition;$mode['definitionHash']=hash('sha256',$definition);
                } catch (Throwable $e) {$mode['reason']=$e->getMessage();$report['complete']=false;}
            } else {$report['complete']=false;if ($info['usable']==='yes') $mode['reason']='CPU validation time limit reached; this level remains unconfirmed.';}
            $report['modes'][]=$mode;
        }
    } catch (Throwable $e) {$report['available']=false;$report['complete']=false;$report['modes']=[];$report['error']=$e->getMessage();}
    return $report;
}

function unmCpuCompatibilityLocal(array $request=[]): array {
    $machine=unmCpuCompatibilityRequest($request);$cache=unmCpuCompatibilityCache();
    $cache->request($machine,static function(string $machine):void {
        $payload=base64_encode(json_encode(['machine'=>$machine],JSON_THROW_ON_ERROR));
        $r=unmRun('nohup setsid /usr/local/sbin/unmotion-cpu-compatibility collect '.escapeshellarg($payload).' </dev/null >/dev/null 2>&1 &',null,2);
        if ($r['code']!==0) throw new RuntimeException('Unable to start the local CPU collector.');
    });
    return $cache->view($machine);
}

/** No hypervisor queries, cache writes or collector launches on the paired RPC. */
function unmCpuCompatibilityCached(array $request=[]): array {
    return unmCpuCompatibilityCache(true)->view(unmCpuCompatibilityRequest($request));
}

/** Advisory host catalogue. The underlying machine-specific observations stay intact. */
function unmCpuCompatibilityOverview(array $reports): array {
    $first=$reports['q35']??[];
    $result=['schemaVersion'=>2,'overviewVersion'=>1,'hostId'=>$first['hostId']??'','hostname'=>$first['hostname']??'unknown','observedAt'=>null,'displayOnly'=>true,'available'=>false,'complete'=>false,'context'=>['architecture'=>'x86_64','virtType'=>'kvm','machine'=>'reference-q35-i440fx'],'referenceContexts'=>[],'referenceDifferences'=>0,'modes'=>[],'error'=>''];
    $refreshing=false;
    foreach(['q35','pc'] as $alias){
        $r=$reports[$alias]??[];$refreshing=$refreshing||!empty($r['cache']['refreshing']);
        $result['referenceContexts'][]=['family'=>$alias==='q35'?'Q35':'i440fx','machine'=>$r['context']['machine']??$alias,'observedAt'=>$r['observedAt']??null,'available'=>!empty($r['available'])];
    }
    $result['cache']=['cached'=>false,'refreshing'=>$refreshing,'status'=>$refreshing?'collecting':'unavailable','identity'=>hash('sha256',json_encode($reports,JSON_THROW_ON_ERROR))];
    try{
        $indexes=[];$identities=[];$times=[];$vendor=null;$result['complete']=true;
        foreach(['q35','pc'] as $alias){
            $r=$reports[$alias]??[];
            if(empty($r['available']))throw new RuntimeException(($alias==='q35'?'Q35':'i440fx').' reference CPU cache is unavailable. '.($r['error']??'Open CPU compatibility on that host to collect it.'));
            if(($r['schemaVersion']??0)!==2||($r['displayOnly']??false)!==true||!is_string($r['hostId']??null)||$r['hostId']===''||$r['hostId']!==$result['hostId']||!is_array($r['modes']??null)||!$r['modes']||count($r['modes'])>1024)throw new RuntimeException('Invalid cached CPU reference report.');
            $context=$r['context']??[];
            if(($context['architecture']??'')!=='x86_64'||($context['virtType']??'')!=='kvm'||unmCpuCompatibilityMachineFamily((string)($context['machine']??''))!==($alias==='q35'?'q35':'i440fx'))throw new RuntimeException('Unexpected CPU reference machine context.');
            if(!is_string($context['vendor']??null)||$context['vendor']===''||($vendor!==null&&$vendor!==$context['vendor']))throw new RuntimeException('CPU reference vendors changed or are unavailable.');
            if(!is_string($r['cache']['identity']??null)||$r['cache']['identity']==='')throw new RuntimeException('CPU reference cache identity is unavailable.');
            $vendor=$context['vendor'];$identities[]=$r['cache']['identity'];
            if(!is_string($r['observedAt']??null)||!preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:Z|[+-][0-9]{2}:[0-9]{2})\z/D',$r['observedAt'])||($time=strtotime($r['observedAt']))===false)throw new RuntimeException('CPU reference timestamp is unavailable.');
            $times[$r['observedAt']]=$time;$result['complete']=$result['complete']&&!empty($r['complete']);$indexes[$alias]=[];
            foreach($r['modes'] as $mode){
                if(!is_array($mode)||!is_string($mode['id']??null)||strlen($mode['id'])>192||!preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.+-]{0,80}-v[1-9][0-9]*\z/D',$mode['id'])||isset($indexes[$alias][$mode['id']])||!in_array($mode['status']??'',['supported','unsupported','unknown'],true))throw new RuntimeException('Invalid cached CPU reference level.');
                $indexes[$alias][$mode['id']]=$mode;
            }
        }
        if($identities[0]===''||!hash_equals($identities[0],$identities[1]))throw new RuntimeException('CPU reference identity changed between observations. Read results again after local collection.');
        $ids=array_unique(array_merge(array_keys($indexes['q35']),array_keys($indexes['pc'])));sort($ids,SORT_NATURAL);
        foreach($ids as $id){
            $a=$indexes['q35'][$id]??null;$b=$indexes['pc'][$id]??null;
            $mode=['id'=>$id,'status'=>'unknown','deprecated'=>!empty($a['deprecated'])||!empty($b['deprecated']),'reason'=>'Not validated in both automatic reference contexts.'];
            if(($a['status']??'')==='supported'&&($b['status']??'')==='supported'){
                try{
                    $definitions=[];
                    foreach([$a,$b] as $source){
                        $xml=unmCpuCompatibilityDefinition((string)($source['cpuXml']??''));$hash=hash('sha256',$xml);
                        if(!is_string($source['definitionHash']??null)||!hash_equals($hash,$source['definitionHash']))throw new RuntimeException('Cached CPU definition hash is invalid.');
                        $definitions[]=$xml;
                    }
                    if($definitions[0]!==$definitions[1]){++$result['referenceDifferences'];throw new RuntimeException('Q35 and i440fx reference definitions differ; VM-specific validation is required.');}
                    $mode['status']='supported';$mode['cpuXml']=$definitions[0];$mode['definitionHash']=hash('sha256',$definitions[0]);$mode['reason']='The exact definition is validated in both Q35 and i440fx reference contexts.';
                }catch(Throwable $e){$mode['reason']=$e->getMessage();}
            }elseif(($a['status']??'')==='unsupported'||($b['status']??'')==='unsupported'){$mode['status']='unsupported';$mode['reason']='This level is unsupported in at least one reference context.';}
            if($mode['status']==='unknown')$result['complete']=false;
            $result['modes'][]=$mode;
        }
        asort($times,SORT_NUMERIC);$result['observedAt']=array_key_first($times);$result['context']['vendor']=$vendor;$result['available']=true;
        $result['cache']['cached']=true;$result['cache']['refreshing']=false;$result['cache']['status']='ready';
    }catch(Throwable $e){$result['complete']=false;$result['modes']=[];$result['error']=$e->getMessage();}
    return $result;
}

function unmCpuCompatibilityLocalOverview(bool $queue=true): array {
    $cache=unmCpuCompatibilityCache(!$queue);$launched=false;$reports=[];
    foreach(['q35','pc'] as $machine){
        if($queue)$cache->request($machine,static function()use(&$launched):void{
            if($launched)return;$launched=true;
            $r=unmRun('nohup setsid /usr/local/sbin/unmotion-cpu-compatibility warmup </dev/null >/dev/null 2>&1 &',null,2);
            if($r['code']!==0)throw new RuntimeException('Unable to start the automatic local CPU collector.');
        });
        $reports[$machine]=$cache->view($machine);
    }
    return unmCpuCompatibilityOverview($reports);
}

/** Effective CPU identity, not processor numbering, frequency or load. */
function unmCpuCompatibilityCpuFingerprint(string $cpuinfo,string $architecture): string {
    $identities=[];
    foreach(preg_split('/\n\s*\n/',trim($cpuinfo))?:[] as $processor){
        $fields=[];
        foreach(preg_split('/\R/',$processor)?:[] as $line){
            $parts=explode(':',$line,2);if(count($parts)!==2)continue;$key=trim($parts[0]);
            if(in_array($key,['vendor_id','cpu family','model','model name','stepping','microcode','flags','Features','CPU implementer','CPU architecture','CPU variant','CPU part','CPU revision'],true)){
                $value=trim($parts[1]);
                if(in_array($key,['flags','Features'],true)){$flags=preg_split('/\s+/',$value);sort($flags,SORT_STRING);$value=implode(' ',array_unique($flags));}
                $fields[$key]=$value;
            }
        }
        if($fields){ksort($fields,SORT_STRING);$identities[json_encode($fields,JSON_THROW_ON_ERROR)]=true;}
    }
    if(!$identities)throw new RuntimeException('CPU identity is unavailable; cached support cannot be confirmed.');
    $identities=array_keys($identities);sort($identities,SORT_STRING);
    return hash('sha256',json_encode([$architecture,$identities],JSON_THROW_ON_ERROR));
}

/** Cheap local file observations; never run virsh/QEMU while serving a cache. */
function unmCpuCompatibilityFingerprint(): string {
    $cpu=unmCpuCompatibilityCpuFingerprint((string)@file_get_contents('/proc/cpuinfo'),php_uname('m'));
    $paths=['/usr/local/sbin/qemu','/usr/bin/qemu-system-x86_64','/usr/sbin/libvirtd','/usr/sbin/virtqemud','/usr/bin/virsh','/etc/unraid-version'];
    foreach(['/usr/share/libvirt/cpu_map/*.xml','/var/log/packages/libvirt-*','/var/log/packages/qemu-*'] as $pattern)$paths=array_merge($paths,glob($pattern)?:[]);
    sort($paths,SORT_STRING);$context=[];
    foreach($paths as $path){
        clearstatcache(true,$path);$real=realpath($path);$s=$real!==false?@stat($real):false;if(!$s)continue;
        if(str_starts_with($path,'/var/log/packages/'))$context[]=[$path];
        elseif(str_starts_with($path,'/usr/share/libvirt/cpu_map/')||$path==='/etc/unraid-version')$context[]=[$path,hash_file('sha256',$real)];
        else $context[]=[$path,$real,$s['size'],$s['mtime']];
    }
    return hash('sha256',json_encode([2,$cpu,php_uname('r'),$context],JSON_THROW_ON_ERROR));
}

/** CPU reports are non-secret observations; normal Unraid boot media is FAT. */
final class UnmCpuCompatibilityStore {
    private ?UnmInventoryCache $locks=null;
    private bool $fat=false;
    public function __construct(public readonly string $directory,private string $runtimeDirectory,private bool $readOnly=false) {
        for($parent=$directory;$parent!==dirname($parent);$parent=dirname($parent))if(is_link($parent))throw new RuntimeException('CPU cache path is redirected.');
        if(!$readOnly&&!is_dir($directory)&&!mkdir($directory,0700,true)&&!is_dir($directory))throw new RuntimeException('CPU cache directory is unavailable.');
        $longest=0;
        foreach(file('/proc/self/mountinfo',FILE_IGNORE_NEW_LINES)?:[] as $line){
            $parts=explode(' - ',$line,2);$fields=explode(' ',$parts[0]);$mount=preg_replace_callback('/\\\\([0-7]{3})/',fn($m)=>chr(octdec($m[1])),$fields[4]??'');
            if($mount!==''&&($directory===$mount||str_starts_with($directory,rtrim($mount,'/').'/'))&&strlen($mount)>=$longest){$longest=strlen($mount);$this->fat=in_array(explode(' ',$parts[1]??'')[0],['vfat','msdos','exfat'],true);}
        }
        $s=@lstat($directory);if(($s&&($s['uid']!==posix_geteuid()||(!$this->fat&&($s['mode']&0022)!==0)))||(!$s&&!$readOnly))throw new RuntimeException('Unsafe CPU cache permissions.');
        if(!$readOnly)$this->locks=new UnmInventoryCache($runtimeDirectory,'','');
    }
    private function path(string $name):string {
        if(!preg_match('/\Acontext-[a-f0-9]{64}\.json\z/D',$name))throw new RuntimeException('Invalid CPU cache filename.');
        $path=$this->directory.'/'.$name;clearstatcache(true,$path);
        if(is_link($path)||(file_exists($path)&&(!is_file($path)||fileowner($path)!==posix_geteuid()||(lstat($path)['nlink']??0)!==1)))throw new RuntimeException('Unsafe CPU cache file.');
        return $path;
    }
    public function read(string $name):array {
        $path=$this->path($name);if(!is_file($path)||filesize($path)>8388608)return [];
        $data=json_decode((string)file_get_contents($path),true,32);return is_array($data)?$data:[];
    }
    public function write(string $name,array $data):void {
        if($this->readOnly)throw new RuntimeException('The CPU report interface is cache-read-only.');
        $path=$this->path($name);$json=json_encode($data,JSON_THROW_ON_ERROR);if(strlen($json)>8388608)throw new RuntimeException('CPU cache is too large.');
        $temporary=tempnam($this->directory,'.cpu-');if($temporary===false)throw new RuntimeException('Cannot stage CPU cache.');
        try{
            $mode=@chmod($temporary,0600);if((!$mode&&!$this->fat)||file_put_contents($temporary,$json,LOCK_EX)!==strlen($json)||!rename($temporary,$path))throw new RuntimeException('Cannot publish CPU cache.');
        }finally{if(is_file($temporary))unlink($temporary);}
    }
    public function lock(string $name) {if(!$this->locks)throw new RuntimeException('The CPU report interface cannot launch or collect.');return $this->locks->lock($name);}
    public function running():bool {
        $path=$this->runtimeDirectory.'/collector.lock';
        if(!file_exists($path))return false;
        if(is_link($this->runtimeDirectory)||is_link($path)||fileowner($path)!==posix_geteuid())throw new RuntimeException('Unsafe CPU collector lock.');
        $h=fopen($path,'r');if(!$h)return false;
        try{return !flock($h,LOCK_EX|LOCK_NB);}finally{fclose($h);}
    }
}

/** Persistent observations survive page refreshes, plugin updates and reboots. */
final class UnmCpuCompatibilityCache {
    private UnmCpuCompatibilityStore $store;
    public function __construct(string $directory,private string $fingerprint,private string $hostId,private string $hostname,private string $bootId,?string $runtimeDirectory=null,bool $readOnly=false) {
        $this->store=new UnmCpuCompatibilityStore($directory,$runtimeDirectory??$directory.'/locks',$readOnly);
    }
    private function name(string $machine):string {unmCpuCompatibilityRequest(['machine'=>$machine]);return 'context-'.hash('sha256',$machine).'.json';}
    private function state(string $machine):array {
        $state=$this->store->read($this->name($machine));
        if(($state['schema']??0)===2&&($state['fingerprint']??'')===$this->fingerprint&&($state['hostId']??'')===$this->hostId)return $state;
        // q35/pc aliases and their resolved version share the same observation.
        foreach(array_slice(glob($this->store->directory.'/context-*.json')?:[],0,32) as $file){
            $other=$this->store->read(basename($file));
            if(($other['schema']??0)===2&&($other['fingerprint']??'')===$this->fingerprint&&($other['hostId']??'')===$this->hostId&&($other['report']['context']['machine']??'')===$machine)return $other;
        }
        return [];
    }
    public function view(string $machine):array {
        $state=$this->state($machine);$report=$state['report']??[];
        if(!is_array($report)||($report&&(($report['schemaVersion']??0)!==2||($report['hostId']??'')!==$this->hostId||!is_array($report['context']??null)||!is_array($report['modes']??null))))$report=[];
        $running=$this->store->running();
        $requested=(int)($state['requestedAt']??0);
        $pending=($state['bootId']??'')===$this->bootId&&$requested<=time()&&$requested>time()-10;
        if(!$report)$report=['schemaVersion'=>2,'hostId'=>$this->hostId,'hostname'=>$this->hostname,'displayOnly'=>true,'available'=>false,'complete'=>false,'observedAt'=>null,'context'=>['architecture'=>'x86_64','virtType'=>'kvm','machine'=>$machine],'modes'=>[],'error'=>'CPU results have not been collected for this hardware/software and machine context. Open CPU compatibility on this host to collect them.'];
        $report['cache']=['cached'=>(bool)($report['available']??false),'refreshing'=>empty($report['available'])&&($running||$pending),'status'=>!empty($report['available'])?'ready':(($running||$pending)?'collecting':(!empty($state['attemptAt'])?'unavailable':'missing')),'identity'=>$this->fingerprint];
        if($report['cache']['refreshing'])$report['error']='CPU compatibility is being collected locally in the background.';
        return $report;
    }
    public function request(string $machine,callable $launch):void {
        $lock=$this->store->lock('request.lock');if(!$lock)return;
        try{
            $state=$this->state($machine);$view=$this->view($machine);
            $age=time()-(int)($state['attemptAt']??0);
            if(!empty($view['available'])||$view['cache']['refreshing']||($age>=0&&$age<60))return;
            if(count(glob($this->store->directory.'/context-*.json')?:[])>=32&&!is_file($this->store->directory.'/'.$this->name($machine)))throw new RuntimeException('CPU cache context limit reached. Use an already collected machine type.');
            $record=['schema'=>2,'hostId'=>$this->hostId,'fingerprint'=>$this->fingerprint,'bootId'=>$this->bootId,'requestedAt'=>time(),'attemptAt'=>time()];
            $this->store->write($this->name($machine),$record);
            try{$launch($machine);}catch(Throwable $e){$record['requestedAt']=0;$this->store->write($this->name($machine),$record);throw $e;}
        }finally{fclose($lock);}
    }
    public function collect(string $machine,callable $inspect,callable $identity):bool {
        $lock=$this->store->lock('collector.lock');if(!$lock)return false;
        try{
            if(!empty($this->view($machine)['available']))return false;
            if(!hash_equals($this->fingerprint,$identity()))throw new RuntimeException('CPU context changed before collection.');
            $report=$inspect(['machine'=>$machine]);
            if(!hash_equals($this->fingerprint,$identity()))throw new RuntimeException('CPU context changed during collection; results were discarded.');
            if(($report['schemaVersion']??0)!==2||($report['hostId']??'')!==$this->hostId||strlen(json_encode($report,JSON_THROW_ON_ERROR))>2097152)throw new RuntimeException('Invalid or oversized local CPU observation.');
            $this->store->write($this->name($machine),['schema'=>2,'hostId'=>$this->hostId,'fingerprint'=>$this->fingerprint,'bootId'=>$this->bootId,'attemptAt'=>time(),'report'=>$report]);
            return true;
        }finally{fclose($lock);}
    }
}

function unmCpuCompatibilityCache(bool $readOnly=false):UnmCpuCompatibilityCache {
    $root=UNM_BOOT_DIR;
    if(is_link($root)||!is_dir($root))throw new RuntimeException('CPU cache root is unavailable or redirected.');
    $runtime='/run/unmotion';
    if(is_link($runtime)||(!$readOnly&&!is_dir($runtime)&&!mkdir($runtime,0700)&&!is_dir($runtime)))throw new RuntimeException('CPU cache runtime is unavailable.');
    $s=@lstat($runtime);if(($s&&($s['uid']!==posix_geteuid()||($s['mode']&0022)!==0))||(!$s&&!$readOnly))throw new RuntimeException('Unsafe CPU cache runtime.');
    return new UnmCpuCompatibilityCache($root.'/cpu-compatibility',unmCpuCompatibilityFingerprint(),unmHostId(),gethostname()?:'unknown',trim((string)file_get_contents('/proc/sys/kernel/random/boot_id')),$runtime.'/cpu-compatibility',$readOnly);
}

function unmCpuCompatibilityCommon(array $local,?array $remote): array {
    $remoteModes=[];
    foreach (($remote['modes']??[]) as $mode) if (is_array($mode) && is_string($mode['id']??null)) $remoteModes[$mode['id']]=$mode;
    $contextMatch=$remote && ($remote['schemaVersion']??null)===2 && !empty($remote['available']) && !empty($local['available']);
    if(($local['overviewVersion']??null)!==($remote['overviewVersion']??null))$contextMatch=false;
    foreach (['architecture','virtType','machine','vendor'] as $field) if (($local['context'][$field]??null)!==($remote['context'][$field]??null) || empty($local['context'][$field])) $contextMatch=false;
    $rows=[];
    foreach ($local['modes']??[] as $mode) {
        $other=$remoteModes[$mode['id']]??null;$common=null;$reason='Check a paired host to compare this level.';
        if ($remote!==null) {
            $reason=(!empty($remote['available']) && !empty($local['available']))?'The hosts have different machine types, architectures or CPU vendors.':'Peer CPU compatibility is unavailable.';
            if ($contextMatch) {
                $common=false;$reason='This level is not validated on both hosts.';
                if ($mode['status']==='supported' && ($other['status']??'')==='supported') {
                    try {
                        $definition=unmCpuCompatibilityDefinition((string)($other['cpuXml']??''));
                        $common=hash_equals((string)$mode['definitionHash'],hash('sha256',$definition));
                        $reason=$common?'The same CPU definition is validated on both hosts.':'The hosts report different CPU definitions for this level.';
                    } catch (Throwable $e) {$reason='The peer CPU definition is invalid.';}
                } elseif (($mode['status']??'')==='unknown' || ($other['status']??'')==='unknown' || (!$other && empty($remote['complete']))) $common=null;
            }
        }
        $rows[]=['id'=>$mode['id'],'localStatus'=>$mode['status'],'peerStatus'=>$other['status']??'unknown','common'=>$common,'reason'=>$reason];
    }
    return $rows;
}

function unmCpuCompatibilityPeerConnection(string $id): array {
    $peer=unmPeer($id);
    $caps=unmRemote($peer,'/usr/local/sbin/unmotion-agent capabilities',20);
    $capabilities=$caps['code']===0?json_decode($caps['stdout'],true):null;
    if (!is_array($capabilities) || ($capabilities['hostId']??'')!==($peer['hostId']??'') || ($capabilities['hostId']??'')===unmHostId()) throw new RuntimeException('Peer CPU capability identity could not be verified.');
    if (($capabilities['cpuCompatibilityVersion']??null)!==2) throw new RuntimeException('This peer does not advertise cached CPU compatibility. Update both peers to 0.5.0-beta1 or later.');
    return $peer;
}

function unmCpuCompatibilityPeerReport(array $peer,string $machine): array {
    unmCpuCompatibilityRequest(['machine'=>$machine]);
    $request=base64_encode(json_encode(['machine'=>$machine],JSON_THROW_ON_ERROR));
    $result=unmRemote($peer,'/usr/local/sbin/unmotion-agent cpu-compatibility '.escapeshellarg($request),15);
    if ($result['code']!==0 || strlen($result['stdout'])>2097152) throw new RuntimeException('Peer CPU compatibility request failed.');
    $report=json_decode($result['stdout'],true,32,JSON_THROW_ON_ERROR);
    if (!is_array($report) || ($report['schemaVersion']??null)!==2 || ($report['hostId']??'')!==$peer['hostId'] || !is_array($report['modes']??null) || count($report['modes'])>1024 || !is_array($report['cache']??null)) throw new RuntimeException('Invalid peer CPU capability response.');
    return $report;
}

function unmCpuCompatibilityPeer(string $id,string $machine): array {
    return unmCpuCompatibilityPeerReport(unmCpuCompatibilityPeerConnection($id),$machine);
}

function unmCpuCompatibilityPeerOverview(string $id): array {
    $peer=unmCpuCompatibilityPeerConnection($id);$reports=[];
    foreach(['q35','pc'] as $machine)$reports[$machine]=unmCpuCompatibilityPeerReport($peer,$machine);
    return unmCpuCompatibilityOverview($reports);
}
