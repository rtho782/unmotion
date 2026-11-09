<?php
// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
declare(strict_types=1);

/** Convert a hardware model expansion into an explicit guest CPU contract.
 * VMX sub-capabilities have no meaning when the selected model omits VMX.
 * Do not enable nested virtualization merely to satisfy those latent bits.
 * QEMU's virtual hypervisor bit is separate from the physical CPU model.
 */
function unmCpuConfigGuestDefinition(string $definition,DOMXPath $vm): string {
    $dom=unmCpuCompatibilityXml(unmCpuCompatibilityDefinition($definition));$xp=new DOMXPath($dom);
    if(!$xp->query('/cpu/feature[@name="vmx" and @policy="require"]')->length){
        $subfeatures=$xp->query('/cpu/feature[starts-with(@name,"vmx-")]');
        foreach($subfeatures as $node)$node->setAttribute('policy','disable');
        if($subfeatures->length&&!$xp->query('/cpu/feature[@name="vmx"]')->length){$node=$dom->createElement('feature');$node->setAttribute('name','vmx');$node->setAttribute('policy','disable');$dom->documentElement->appendChild($node);}
    }
    $visibility='require';$existing=$vm->query('/domain/cpu/feature[@name="hypervisor"]');
    if($existing->length>1)throw new RuntimeException('Ambiguous hypervisor visibility needs manual review.');
    if($existing->length){
        $policy=$existing->item(0)->getAttribute('policy');
        if(in_array($policy,['disable','forbid'],true))$visibility='disable';
        elseif(!in_array($policy,['require','force'],true))throw new RuntimeException('Optional hypervisor visibility needs manual review.');
    }
    foreach($xp->query('/cpu/feature[@name="hypervisor"]') as $node)$node->parentNode->removeChild($node);
    $node=$dom->createElement('feature');$node->setAttribute('name','hypervisor');$node->setAttribute('policy',$visibility);$dom->documentElement->appendChild($node);
    return unmCpuCompatibilityDefinition($dom->saveXML());
}

/** Persistent definitions only. Never changes the CPU of a running QEMU process. */
function unmCpuConfigTransform(string $xml,string $definition): array {
    $dom=unmCpuCompatibilityXml($xml);$xp=new DOMXPath($dom);$root=$dom->documentElement;
    $cpu=unmCpuConfigGuestDefinition($definition,$xp);
    $mask=unmCpuCompatibilityXml($cpu);$mx=new DOMXPath($mask);
    if($mx->query('/cpu/feature[@name="lm" and @policy="require"]')->length!==1)throw new RuntimeException('This level does not provide 64-bit long mode; it cannot be applied to x86_64 VMs.');
    $uuid=strtolower(trim($xp->evaluate('string(/domain/uuid)')));$name=trim($xp->evaluate('string(/domain/name)'));
    if($root->tagName!=='domain'||$root->namespaceURI||$xp->query('/domain/uuid')->length!==1||!preg_match('/\A[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\z/D',$uuid)||$name==='')throw new RuntimeException('Invalid persistent VM identity.');
    $machine=$xp->evaluate('string(/domain/os/type/@machine)');
    if($root->getAttribute('type')!=='kvm'||$xp->evaluate('string(/domain/os/type/@arch)')!=='x86_64'||unmCpuCompatibilityMachineFamily($machine)==='')throw new RuntimeException('Only x86_64 KVM VMs using Q35 or i440fx are supported.');
    if($xp->query('//*[namespace-uri()="urn:unmotion:recovery:1"]')->length)throw new RuntimeException('Recovery-owned VM: reconcile recovery before changing its CPU.');
    foreach($xp->query('//*[namespace-uri()="http://libvirt.org/schemas/domain/qemu/1.0" and local-name()="arg"]') as $arg)if(preg_match('/\A-(?:cpu|smp)(?:=|\z)/D',$arg->getAttribute('value')))throw new RuntimeException('A custom QEMU CPU/topology override needs manual review.');
    $old=$xp->query('/domain/cpu');if($old->length>1)throw new RuntimeException('Multiple CPU definitions need manual review.');
    $replacement=$dom->importNode($mask->documentElement,true);
    // This is libvirt's normalized startup default, not check=none. Libvirt
    // records the realized contract in live XML for future migration checks.
    $replacement->setAttribute('check','partial');
    if($old->length){
        $current=$old->item(0);
        foreach($current->childNodes as $node){
            if(!$node instanceof DOMElement)continue;
            if($node->namespaceURI||!in_array($node->tagName,['model','vendor','feature','cache','topology','numa'],true))throw new RuntimeException('Special CPU settings need manual review: '.$node->tagName.'.');
            if(in_array($node->tagName,['topology','numa'],true))$replacement->appendChild($node->cloneNode(true));
        }
        $root->replaceChild($replacement,$current);
    }else $root->appendChild($replacement);
    return ['uuid'=>$uuid,'name'=>$name,'machine'=>$machine,'cpuXml'=>$cpu,'xml'=>$dom->saveXML(),'hash'=>unmCpuConfigHash($xml),'targetHash'=>unmCpuConfigHash($dom->saveXML())];
}

function unmCpuConfigHash(string $xml): string {return hash('sha256',unmCpuCompatibilityXml($xml)->C14N());}

/** Read-only policy inventory; active/resumable work must not retain an older CPU XML. */
function unmCpuConfigGuard(string $base,string $uuid): void {
    if(unmNativeRecordForVm($base,$uuid)!==null)throw new RuntimeException('Recovery-managed VM: disarm/reconcile recovery before changing its CPU.');
    if(unmLiveNativeRecord($base,$uuid)!==null)throw new RuntimeException('Live-managed VM: CPU changes are outside this beta\'s guarded ownership workflow.');
    $groups=['seeds/*/seed.json','replications/*/policy.json','jobs/*/job.json'];
    foreach($groups as $group)foreach(glob($base.'/'.$group)?:[] as $path){
        $record=json_decode((string)@file_get_contents($path),true);
        if(!is_array($record))throw new RuntimeException('An unreadable unMotion work record prevents CPU changes.');
        if(strtolower((string)($record['vmUuid']??''))!==$uuid)continue;
        if(str_starts_with($group,'replications/'))throw new RuntimeException('Replication policy present: remove it before changing the persistent CPU.');
        $state=(string)($record['state']??'');
        $finished=str_starts_with($group,'jobs/')?['COMPLETE','COMPLETE_CLEANED','CANCELLED','RESOLVED','REMOVED']:['REMOVED','COMPLETE'];
        if(!in_array($state,$finished,true))throw new RuntimeException('Prepared, active or unresolved unMotion work exists for this VM.');
    }
}

/** Injectable boundaries support isolated tests, never browser-supplied XML/commands. */
final class UnmCpuConfigService {
    private $run;private $overview;private $guard;private $schema;
    public function __construct(callable $run,callable $overview,callable $guard,private string $locks,private string $backups,private string $identity,private ?string $globalLock=null,?callable $schema=null) {
        $this->run=$run;$this->overview=$overview;$this->guard=$guard;
        $this->schema=$schema??static function(string $xml): bool {
            $dom=unmCpuCompatibilityXml($xml);$previous=libxml_use_internal_errors(true);
            try{return $dom->relaxNGValidate('/usr/share/libvirt/schemas/domain.rng');}finally{libxml_clear_errors();libxml_use_internal_errors($previous);}
        };
    }
    private function command(array $args,?string $input=null): string {
        $r=($this->run)($args,$input,15);
        if($r['code']!==0)throw new RuntimeException(trim($r['stderr'])?:'Libvirt did not complete the CPU configuration check.');
        return $r['stdout'];
    }
    private function lock(string $path) {
        if(is_link($path))throw new RuntimeException('Invalid CPU configuration lock.');
        $f=fopen($path,'c');if($f===false)throw new RuntimeException('Cannot open CPU configuration lock.');@chmod($path,0600);
        if(!flock($f,LOCK_EX|LOCK_NB)){fclose($f);throw new RuntimeException('An unMotion operation is busy; retry CPU configuration when it finishes.');}return $f;
    }
    private function info(string $uuid): string {
        $info=$this->command(['virsh','dominfo',$uuid]);
        if(!preg_match('/^Persistent:\s+yes\s*$/mi',$info)||!preg_match('/^Managed save:\s+no\s*$/mi',$info))throw new RuntimeException('VM is transient, has a saved memory state, or its persistent state cannot be proven.');
        return $info;
    }
    private function save(string $path,string $value): void {
        // All paths are generated below a new private batch directory, never VM names.
        $tmp=$path.'.tmp';$f=fopen($tmp,'x');if($f===false)throw new RuntimeException('Cannot create CPU XML backup.');
        try{if(fwrite($f,$value)!==strlen($value)||!fflush($f)||(function_exists('fsync')&&!fsync($f)))throw new RuntimeException('Cannot persist CPU XML backup.');}finally{fclose($f);}
        @chmod($tmp,0600);if(!rename($tmp,$path))throw new RuntimeException('Cannot commit CPU XML backup.');
    }
    public function execute(string $id,string $definitionHash,string $cacheIdentity,?string $expected=null): array {
        if(!preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.+-]{0,191}\z/D',$id)||!preg_match('/\A[a-f0-9]{64}\z/D',$definitionHash)||!preg_match('/\A[a-f0-9]{64}\z/D',$cacheIdentity)||($expected!==null&&!preg_match('/\A[a-f0-9]{64}\z/D',$expected)))throw new InvalidArgumentException('Invalid CPU selection or review.');
        $held=[];
        try{
            $held[]=$this->lock($this->globalLock??$this->locks.'/unmotion.lock');
            $view=($this->overview)();
            if(empty($view['available'])||!hash_equals($cacheIdentity,(string)($view['cache']['identity']??'')))throw new RuntimeException('CPU results changed; read results and review again.');
            $modes=array_values(array_filter($view['modes']??[],static fn($m)=>($m['id']??'')===$id&&($m['status']??'')==='supported'));
            if(count($modes)!==1||!hash_equals($definitionHash,(string)($modes[0]['definitionHash']??'')))throw new RuntimeException('The selected CPU definition is no longer validated locally.');
            $cpu=unmCpuCompatibilityDefinition((string)$modes[0]['cpuXml']);
            if(!hash_equals($definitionHash,hash('sha256',$cpu)))throw new RuntimeException('Invalid cached CPU definition.');
            // Baseline expansion may choose another equivalent model name.
            // Persist the versioned level the user selected, not that internal name.
            $named=unmCpuCompatibilityXml($cpu);$nx=new DOMXPath($named);$nx->query('/cpu/model')->item(0)->nodeValue=$id;
            $namedCpu=$named->saveXML();
            $seed=clone $named;$sx=new DOMXPath($seed);foreach($sx->query('/cpu/feature') as $node)$node->parentNode->removeChild($node);
            $seedXml=$seed->saveXML();
            $ids=preg_split('/\s+/',trim($this->command(['virsh','list','--all','--persistent','--uuid'])),-1,PREG_SPLIT_NO_EMPTY);sort($ids,SORT_STRING);
            if(count($ids)>256||count(array_unique($ids))!==count($ids))throw new RuntimeException('VM inventory is too large or ambiguous for one CPU configuration batch.');
            $rows=[];$originals=[];$validated=[];$guestValidated=[];$bytes=0;
            foreach($ids as $uuid){
                if(!preg_match('/\A[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\z/D',$uuid))throw new RuntimeException('Libvirt returned an invalid persistent VM identity.');
                $row=['uuid'=>$uuid,'name'=>$uuid,'status'=>'skipped','reason'=>''];
                try{
                    $held[]=$this->lock($this->locks.'/unmotion-'.$uuid.'.lock');
                    $xml=$this->command(['virsh','dumpxml',$uuid,'--inactive']);
                    if(($bytes+=strlen($xml))>16777216)throw new RuntimeException('Persistent XML exceeds the 16 MiB batch budget.');
                    $doc=unmCpuCompatibilityXml($xml);$row['name']=(new DOMXPath($doc))->evaluate('string(/domain/name)');
                    ($this->guard)($uuid);$this->info($uuid);
                    $change=unmCpuConfigTransform($xml,$namedCpu);
                    if($change['uuid']!==$uuid)throw new RuntimeException('Persistent VM identity changed.');
                    $machine=$change['machine'];$row['machine']=$machine;
                    if(!isset($validated[$machine])){
                        if(count($validated)>=16)throw new RuntimeException('Too many machine versions for one batch.');
                        try{
                            $expanded=$this->command(['virsh','hypervisor-cpu-baseline','/dev/stdin','--features','--migratable','--virttype','kvm','--arch','x86_64','--machine',$machine],$seedXml);
                            if(!hash_equals($definitionHash,hash('sha256',unmCpuCompatibilityDefinition($expanded))))throw new RuntimeException('This VM machine version expands the CPU differently.');
                            $this->command(['virsh','hypervisor-cpu-compare','/dev/stdin','--error','--virttype','kvm','--arch','x86_64','--machine',$machine],$cpu);
                            $validated[$machine]='';
                        }catch(Throwable $e){$validated[$machine]=$e->getMessage();}
                    }
                    if($validated[$machine]!=='')throw new RuntimeException($validated[$machine]);
                    $guestKey=$machine.'|'.hash('sha256',$change['cpuXml']);
                    if(!isset($guestValidated[$guestKey])){
                        try{$this->command(['virsh','hypervisor-cpu-compare','/dev/stdin','--error','--virttype','kvm','--arch','x86_64','--machine',$machine],$change['cpuXml']);$guestValidated[$guestKey]='';}
                        catch(Throwable $e){$guestValidated[$guestKey]=$e->getMessage();}
                    }
                    if($guestValidated[$guestKey]!=='')throw new RuntimeException($guestValidated[$guestKey]);
                    if(!($this->schema)($change['xml']))throw new RuntimeException('The proposed persistent XML does not pass the installed libvirt schema.');
                    $row+=['hash'=>$change['hash'],'targetHash'=>$change['targetHash']];
                    $row['status']=$change['hash']===$change['targetHash']?'unchanged':'eligible';
                    $originals[$uuid]=['original'=>$xml,'target'=>$change['xml']];
                }catch(Throwable $e){$row['reason']=$e->getMessage();}
                $rows[]=$row;
            }
            $digest=hash('sha256',json_encode([$this->identity,$cacheIdentity,$id,$definitionHash,$rows],JSON_THROW_ON_ERROR));
            $answer=['mode'=>$id,'review'=>$digest,'vms'=>$rows,'eligible'=>count(array_filter($rows,static fn($r)=>$r['status']==='eligible')),'applied'=>0,'complete'=>true,'backupDirectory'=>''];
            if($expected===null)return $answer;
            if(!hash_equals($digest,$expected))throw new RuntimeException('VM configuration or eligibility changed since review. Nothing was changed; review again.');
            if(!$answer['eligible'])return $answer;
            if(is_link($this->backups)||(!is_dir($this->backups)&&!mkdir($this->backups,0700,true)))throw new RuntimeException('Cannot create the private CPU XML backup directory.');
            $dir=$this->backups.'/batch-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(8));
            if(!mkdir($dir,0700))throw new RuntimeException('Cannot create a CPU XML backup batch.');
            $answer['backupDirectory']=$dir;
            $answer['complete']=false;
            // Persist every original before the first mutation; no automatic destructive rollback.
            foreach($rows as $row)if($row['status']==='eligible')$this->save($dir.'/'.$row['uuid'].'.xml',$originals[$row['uuid']]['original']);
            $journal=$answer;$journal['complete']=false;$this->save($dir.'/result.json',json_encode($journal,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
            foreach($answer['vms'] as &$row){
                if($row['status']!=='eligible')continue;
                try{
                    ($this->guard)($row['uuid']);$this->info($row['uuid']);
                    if(!hash_equals($row['hash'],unmCpuConfigHash($this->command(['virsh','dumpxml',$row['uuid'],'--inactive']))))throw new RuntimeException('VM XML changed after review; refusing to overwrite it.');
                    $row['status']='applying';$this->save($dir.'/result.json',json_encode($answer,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
                    $this->command(['virsh','define','/dev/stdin','--validate'],$originals[$row['uuid']]['target']);
                    $row['status']='applied';$answer['applied']++;
                    if(!hash_equals($row['targetHash'],unmCpuConfigHash($this->command(['virsh','dumpxml',$row['uuid'],'--inactive']))))throw new RuntimeException('Libvirt returned a different definition after applying; inspect the backup and VM XML.');
                }catch(Throwable $e){$row['status']='needs_review';$row['reason']=$e->getMessage();$answer['complete']=false;break;}
                $this->save($dir.'/result.json',json_encode($answer,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
            }
            unset($row);$answer['complete']=!count(array_filter($answer['vms'],static fn($r)=>in_array($r['status'],['eligible','applying','needs_review'],true)));$this->save($dir.'/result.json',json_encode($answer,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
            return $answer;
        }finally{foreach(array_reverse($held) as $handle){flock($handle,LOCK_UN);fclose($handle);}}
    }
}

function unmCpuConfigService(): UnmCpuConfigService {
    // Locale-stable dominfo parsing. No arbitrary arguments are accepted from the client.
    return new UnmCpuConfigService(static fn($args,$input,$timeout)=>unmRun(array_merge(['env','LC_ALL=C'],$args),$input,$timeout),static fn()=>unmCpuCompatibilityLocalOverview(false),static fn($uuid)=>unmCpuConfigGuard(UNM_BOOT_DIR,$uuid),'/var/lock',UNM_BOOT_DIR.'/cpu-config-backups',unmHostId().'|'.unmRecoveryBootId(),'/var/run/unmotion.lock');
}
