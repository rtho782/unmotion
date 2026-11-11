<?php
// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
declare(strict_types=1);

function unmLiveCommand(array $args,?string $input=null,int $seconds=20): string {
    $r=unmRun(array_merge(['env','LC_ALL=C','LANG=C'],$args),$input,$seconds);if($r['code']!==0)throw new RuntimeException(trim($r['stderr'])?:'Live migration check failed: '.implode(' ',array_slice($args,0,2)));return $r['stdout'];
}
function unmLiveFileSystem(string $path): void {
    $fs=trim(unmLiveCommand(['findmnt','-n','-o','FSTYPE','--target',$path]));if(!in_array($fs,['zfs','xfs','btrfs','ext4'],true))throw new RuntimeException('Live file storage requires a direct local ZFS/XFS/Btrfs/ext4 filesystem, not a user-share or network mount.');
}
function unmLiveSameDisk(string $source,array $stat,string $other,?array $resolution=null): bool {
    if($resolution===null){$lookup=str_starts_with($other,'/mnt/user0/')?'/mnt/user/'.substr($other,11):$other;$resolution=unmResolveUserSharePath($lookup);}
    foreach(array_unique(array_filter(array_merge([$other,$resolution['physicalPath']??''],(array)($resolution['candidates']??[])))) as $probe){if($probe===$source)return true;$s=@stat($probe);if($s&&$s['dev']===$stat['dev']&&$s['ino']===$stat['ino'])return true;}return false;
}
function unmLiveRpc(array $peer,string $action,array $args=[],int $seconds=30): array {
    $r=unmRun(unmLiveSshArgs($peer,['action'=>$action]+$args,'live-call'),null,$seconds);
    if($r['code']!==0)throw new RuntimeException(trim($r['stderr'])?:'Paired live migration request failed.');
    $v=json_decode($r['stdout'],true,64,JSON_THROW_ON_ERROR);if(!is_array($v)||empty($v['success']))throw new RuntimeException((string)($v['error']??'Invalid live migration reply.'));return $v;
}
function unmLiveAssertVersion(array $p,string $hostId,bool $advertisement=false): void {
    if(($p['hostId']??'')!==$hostId||($p[$advertisement?'pluginVersion':'version']??'')!==UNM_VERSION||($p['liveMigrationVersion']??null)!==4)throw new RuntimeException('Live migration requires matching '.UNM_VERSION.' / live capability 4 on both paired hosts. Ordinary protocol-7 Cold/Warm Move compatibility is unchanged.');
}
function unmLiveAuthority(array $j,string $state,string $disk,?array $qemu=null): void {
    $r=['schemaVersion'=>1,'vmUuid'=>$j['vmUuid'],'vmName'=>$j['vmName'],'jobId'=>$j['id'],'role'=>$j['role'],'state'=>$state,'diskPath'=>$disk,'sourceHostId'=>$j['sourceHostId'],'destinationHostId'=>$j['destinationHostId'],'bootId'=>unmNativeBoot(),'expiresAt'=>$j['expiresAt'],'updatedAt'=>gmdate('c')];
    $xml=$j['role']==='source'?($j['spec']['persistentXml']??null):($j['persistentXml']??null);
    if($xml!==null){$xp=new DOMXPath(unmCpuCompatibilityXml($xml));$r['firmware']=unmLiveFirmwareXml($xp,$j['vmUuid']);$r['disks']=unmLiveDisksXml($xp);$r['tpm']=unmLiveTpmXml($xp,$j['vmUuid']);$saved=$j['role']==='source'?($j['spec']['disks']??null):($j['disks']??null);if($saved!==null){$enriched=unmLiveDisks(['disks'=>$saved]);if(array_map('unmLiveDiskIdentity',$enriched)!==$r['disks'])throw new RuntimeException('Authority storage metadata does not match its XML.');$r['disks']=$enriched;}}
    $base=UNM_BOOT_DIR;unmLivePrivateDir($base.'/live-authority');unmLivePrivateDir($base.'/live-managed');
    $lock=unmNativeLock('live-'.$j['vmUuid']);
    try{
        unmNativeWrite($base.'/live-authority/'.$j['vmUuid'].'.json',$r);
        unmNativeWrite($base.'/live-managed/'.$j['vmUuid'].'.json',['schemaVersion'=>1,'vmUuid'=>$j['vmUuid'],'jobId'=>$j['id']]);
        if($qemu)unmNativeWrite('/run/unmotion/lifecycle/live-admissions/'.$j['vmUuid'].'.json',['jobId'=>$j['id'],'bootId'=>unmNativeBoot(),'phase'=>'RUNNING','qemu'=>$qemu]);
        // An authority grant is not acknowledged before both records reach the
        // underlying filesystem. Runtime admissions never convey authority.
        unmLiveCommand(['sync','-f',$base.'/live-authority/'.$j['vmUuid'].'.json']);
        unmLiveCommand(['sync','-f',$base.'/live-managed/'.$j['vmUuid'].'.json']);
    }finally{flock($lock,LOCK_UN);fclose($lock);}
    if(in_array($state,['OWNER','MOVING','RECEIVING'],true))unmLiveZvolRefresh($r);
}
function unmLiveVmState(string $uuid): array {
    unmTransportUuid($uuid);$all=trim(unmLiveCommand(['virsh','list','--all','--uuid']));$ids=$all===''?[]:preg_split('/\s+/',$all);
    foreach($ids as $v)unmTransportUuid($v);
    $state=in_array($uuid,$ids,true)?trim(unmLiveCommand(['virsh','domstate',$uuid])):'undefined';$processes=[];
    foreach(glob('/proc/[0-9]*/cmdline')?:[] as $path){$pid=(int)basename(dirname($path));$exe=(string)@readlink('/proc/'.$pid.'/exe');if(!str_starts_with(basename($exe),'qemu-system-'))continue;$a=explode("\0",rtrim((string)@file_get_contents($path),"\0"));$i=array_search('-uuid',$a,true);if($i!==false&&strtolower((string)($a[$i+1]??''))===$uuid){$p=unmProcessSnapshot($pid);if(!$p)throw new RuntimeException('QEMU lifetime changed during state check.');$processes[]=$p;}}
    if(count($processes)>1||in_array($state,['running','idle','paused','pmsuspended','in shutdown'],true)&&count($processes)!==1||in_array($state,['undefined','shut off'],true)&&$processes)throw new RuntimeException('Libvirt and exact QEMU state disagree.');
    if(!in_array($state,['running','idle','paused','shut off','undefined'],true))throw new RuntimeException('VM state is not safe for live handover: '.$state);
    return ['state'=>$state,'qemuAbsent'=>!$processes,'qemu'=>$processes[0]??null];
}
function unmLiveCpu(string $xml): string {
    $d=unmCpuCompatibilityXml($xml);$xp=new DOMXPath($d);$cpu=$xp->query('/domain/cpu')->item(0);
    if(!$cpu||$cpu->getAttribute('mode')!=='custom'||$cpu->getAttribute('match')!=='exact'||$xp->evaluate('string(/domain/cpu/model/@fallback)')!=='forbid')throw new RuntimeException('Apply a CPU compatibility mode and fully stop/start this VM first.');
    $copy=new DOMDocument();$copy->appendChild($copy->importNode($cpu,true));
    foreach(iterator_to_array($copy->documentElement->childNodes) as $n)if($n instanceof DOMElement&&!in_array($n->tagName,['model','vendor','feature'],true))$copy->documentElement->removeChild($n);
    $copy->documentElement->setAttribute('check','full');
    if((new DOMXPath($copy))->query('/cpu/feature[@name="invtsc" and @policy="require"]')->length)throw new RuntimeException('Non-migratable invariant TSC is not supported.');return $copy->saveXML($copy->documentElement);
}
function unmLiveNestedCpu(string $cpu,?callable $enabled=null): void {
    $d=unmCpuCompatibilityXml($cpu);$xp=new DOMXPath($d);$enabled??='unmCpuNestedEnabled';
    if($xp->query('/cpu/feature[@name="svm" and (@policy="require" or @policy="force")]')->length)throw new RuntimeException('AMD SVM nested hypervisors are supported for CPU configuration, but not live migration in this beta. Use Cold/Warm Move.');
    if($xp->query('/cpu/feature[@name="vmx" and (@policy="require" or @policy="force")]')->length&&!$enabled('vmx'))throw new RuntimeException('Intel VMX is required but KVM nesting is not enabled on this host.');
}
function unmLiveInspectXml(string $xml): array {
    $d=unmCpuCompatibilityXml($xml);$xp=new DOMXPath($d);$uuid=unmTransportUuid(trim($xp->evaluate('string(/domain/uuid)')));$name=trim($xp->evaluate('string(/domain/name)'));
    foreach(['/domain/name','/domain/uuid','/domain/memory','/domain/vcpu','/domain/os/type','/domain/cpu'] as $required)if($xp->query($required)->length!==1)throw new RuntimeException('Duplicated or missing live VM identity/resources.');
    if($d->documentElement->getAttribute('type')!=='kvm'||$name===''||strlen($name)>128||str_contains($name,'/')||preg_match('/[\x00-\x1f]/',$name))throw new RuntimeException('Unsupported live migration domain identity.');
    $machine=$xp->evaluate('string(/domain/os/type/@machine)');if(!preg_match('/\A(?:pc-q35|pc-i440fx)-[0-9]+\.[0-9]+\z/D',$machine)||$xp->evaluate('string(/domain/os/type/@arch)')!=='x86_64')throw new RuntimeException('Live migration requires an exact versioned x86_64 Q35/i440fx machine.');
    if($xp->query('/domain/devices/hostdev|/domain/devices/filesystem|/domain/devices/shmem|/domain/devices/redirdev|/domain/devices/memory|/domain/memoryBacking/hugepages|/domain/launchSecurity|/domain/cpu/numa')->length)throw new RuntimeException('Live migration excludes passthrough, shared memory, huge pages, hotplug memory and NUMA. Use Cold/Warm Move for other supported guests.');
    $firmware=unmLiveFirmwareXml($xp,$uuid);
    $tpm=unmLiveTpmXml($xp,$uuid);
    if($xp->query('/domain/devices/graphics[@type!="vnc"]|/domain/devices/graphics/gl[@enable="yes"]|/domain/devices/video/model/acceleration[@accel3d="yes"]|/domain/devices/audio[@type!="none"]')->length)throw new RuntimeException('Live migration supports VNC/headless display without host GPU acceleration or host audio.');
    foreach($xp->query('//*') as $node)if($node->namespaceURI&&$node->namespaceURI!=='http://unraid'&&$node->namespaceURI!=='unraid')throw new RuntimeException('Custom executable extensions or managed recovery metadata are not supported by live migration.');
    if($xp->query('/domain/cputune|/domain/numatune|/domain/cpu/cache|/domain/cpu/maxphysaddr')->length)throw new RuntimeException('Remove host CPU/NUMA pinning and host-specific CPU cache/address overrides before live migration in this beta.');
    $disks=unmLiveDisksXml($xp);$first=$disks[0];
    foreach($xp->query('/domain/devices/interface') as $nic)if($nic->getAttribute('type')!=='bridge'||$xp->query('./virtualport|./bandwidth|./vlan|./filterref|./script',$nic)->length)throw new RuntimeException('Live migration supports ordinary bridge NICs only, without host-specific scripts/VLAN/filter settings.');
    if($xp->evaluate('string(/domain/memory/@unit)')!=='KiB'||$xp->query('/domain/vcpu[@current and @current != text()]')->length||(int)$xp->evaluate('string(/domain/vcpu)')<1||(int)$xp->evaluate('string(/domain/memory)')<32768)throw new RuntimeException('Unsupported live memory/vCPU units or hotplug configuration.');
    return ['vmUuid'=>$uuid,'vmName'=>$name,'machine'=>$machine,'firmware'=>$firmware,'tpm'=>$tpm,'cpuXml'=>unmLiveCpu($xml),'disks'=>$disks,'diskPath'=>$first['path'],'format'=>$first['format'],'diskTarget'=>$first['target'],'bridges'=>array_map(fn($n)=>$n->getAttribute('bridge'),iterator_to_array($xp->query('/domain/devices/interface/source'))),'memoryKiB'=>(int)$xp->evaluate('string(/domain/memory)'),'vcpus'=>(int)$xp->evaluate('string(/domain/vcpu)')];
}
function unmLiveFirmwareFile(string $path,bool $digest): array {
    clearstatcache(true,$path);$s=@lstat($path);
    if(!$s||is_link($path)||!is_file($path)||realpath($path)!==$path||$s['nlink']!==1||$s['size']<65536||$s['size']>16777216)throw new RuntimeException('Firmware must be an independent regular file of a supported size.');
    $hash=$digest?hash_file('sha256',$path):null;clearstatcache(true,$path);$after=lstat($path);
    foreach(['dev','ino','size','mtime','ctime'] as $key)if($s[$key]!==$after[$key])throw new RuntimeException('Firmware changed during validation.');
    return ['bytes'=>$s['size'],'sha256'=>$hash];
}
function unmLiveFirmwareEvidence(?array $firmware): ?array {
    if($firmware===null)return null;$loader=unmLiveFirmwareFile($firmware['loaderPath'],true);$nv=unmLiveFirmwareFile($firmware['nvramPath'],false);
    return ['loaderPath'=>$firmware['loaderPath'],'loaderSha256'=>$loader['sha256'],'loaderBytes'=>$loader['bytes'],'nvramBytes'=>$nv['bytes']];
}
function unmLiveFirmwareDestination(?array $firmware,?array $evidence): void {
    if($firmware===null){if($evidence!==null)throw new RuntimeException('Unexpected UEFI evidence.');return;}
    if(!$evidence||array_keys($evidence)!==['loaderPath','loaderSha256','loaderBytes','nvramBytes']||$evidence['loaderPath']!==$firmware['loaderPath']||!is_int($evidence['nvramBytes'])||$evidence['nvramBytes']<65536||$evidence['nvramBytes']>16777216)throw new RuntimeException('Missing or invalid source firmware evidence.');
    $loader=unmLiveFirmwareFile($firmware['loaderPath'],true);
    if($loader['sha256']!==$evidence['loaderSha256']||$loader['bytes']!==$evidence['loaderBytes'])throw new RuntimeException('Source and destination OVMF firmware differ. Update both Unraid hosts before live migration.');
}
function unmLiveUndefine(string $uuid,?array $firmware,?array $tpm=null): void {
    $args=['virsh','undefine',$uuid];if($firmware!==null)$args[]='--keep-nvram';if($tpm!==null)$args[]='--keep-tpm';unmLiveCommand($args);
}
function unmLiveFenceProof(): void {
    $proof=unmNativeProofStatus();if(empty($proof['ready']))throw new RuntimeException((string)$proof['reason']);
    $observed=unmNativeJson('/run/unmotion/lifecycle/native-hook-proof.json');if(($observed['liveHelperSha256']??'')!==unmLiveNativeHelperHash())throw new RuntimeException('The installed live migration fence has not been proven active.');
}
function unmLivePlatform(bool $needTpm=false): array {
    unmLiveFenceProof();
    $v=unmLiveCommand(['virsh','version','--daemon']);if(!preg_match('/Running against daemon: 12\.2\.0(?:\s|$)/',$v))throw new RuntimeException('This beta transport is qualified only for libvirt 12.2.0.');
    $caps=unmCpuCompatibilityXml(unmLiveCommand(['virsh','capabilities']));$xp=new DOMXPath($caps);$vendor=$xp->evaluate('string(/capabilities/host/cpu/vendor)');$model=$xp->evaluate('string(/capabilities/host/cpu/model)');
    $cpu=(string)file_get_contents('/proc/cpuinfo');preg_match('/^cpu family\s*:\s*(\d+)/m',$cpu,$family);preg_match('/^model\s*:\s*(\d+)/m',$cpu,$number);
    if(!in_array($vendor,['Intel','AMD'],true)||(int)($family[1]??0)<1||!isset($number[1]))throw new RuntimeException('A usable physical x86 CPU identity is unavailable.');
    return ['version'=>UNM_VERSION,'liveMigrationVersion'=>4,'hostId'=>unmHostId(),'bootId'=>unmNativeBoot(),'vendor'=>$vendor,'model'=>$model,'family'=>(int)($family[1]??0),'modelNumber'=>(int)($number[1]??0),'hypervisor'=>$v,'imageDirectory'=>(string)unmLoadConfig()['image_dir'],'tpm'=>$needTpm?unmLiveTpmPlatform():null];
}
function unmLiveGeneration(array $source,array $destination): bool {
    if($source['vendor']!==$destination['vendor'])return false;
    // Named family chronology, not alphabetical CPUID/model-number ordering.
    $families=['Intel'=>['Conroe','Penryn','Nehalem','Westmere','SandyBridge','IvyBridge','Haswell','Broadwell','Skylake-Client','Skylake-Server','Cascadelake-Server','Cooperlake','Icelake-Server','SapphireRapids','GraniteRapids','DiamondRapids'],'AMD'=>['Opteron_G1','Opteron_G2','Opteron_G3','Opteron_G4','Opteron_G5','EPYC','EPYC-Rome','EPYC-Milan','EPYC-Genoa','EPYC-Turin']];
    $rank=static function($host)use($families){$name=preg_replace('/(?:-noTSX|-IBRS|-v[0-9]+)+$/','',$host['model']);return array_search($name,$families[$host['vendor']]??[],true);};$a=$rank($source);$b=$rank($destination);
    if($a!==false&&$b!==false)return $b>=$a;
    return $source['model']===$destination['model']&&$source['family']>0&&$source['family']===$destination['family']&&$source['modelNumber']===$destination['modelNumber'];
}
function unmLiveValidateDestination(array $spec): void {
    unmLiveCleanupInterlock($spec['vmUuid']);
    $machine=$spec['machine'];unmCpuCompatibilityRequest(['machine'=>$machine]);
    unmLiveCommand(['virsh','domcapabilities','--virttype','kvm','--arch','x86_64','--machine',$machine]);
    unmLiveCommand(['virsh','hypervisor-cpu-compare','/dev/stdin','--error','--virttype','kvm','--arch','x86_64','--machine',$machine],$spec['cpuXml']);
    unmLiveNestedCpu($spec['cpuXml']);
    foreach($spec['bridges'] as $bridge){if(!preg_match('/\A[A-Za-z0-9_.:-]{1,15}\z/D',$bridge)||!is_dir('/sys/class/net/'.$bridge.'/bridge'))throw new RuntimeException('Missing equivalent destination bridge: '.$bridge);}
    $state=unmLiveVmState($spec['vmUuid']);if($state['state']!=='undefined'||!$state['qemuAbsent'])throw new RuntimeException('A destination VM already has this UUID.');
    $names=explode("\n",trim(unmLiveCommand(['virsh','list','--all','--name'])));if(in_array($spec['vmName'],$names,true))throw new RuntimeException('A destination VM already has this name.');
    if(unmNativeRecordForVm(UNM_BOOT_DIR,$spec['vmUuid'])!==null)throw new RuntimeException('Recovery-managed VMs are excluded from live migration.');
    $a=unmLiveNativeRecord(UNM_BOOT_DIR,$spec['vmUuid']);if($a!==null&&!in_array($a['state'],['RETIRED','ABORTED'],true))throw new RuntimeException('Existing live migration authority is not retired.');
}
function unmLiveSource(string $uuid): array {
    unmLiveCleanupInterlock($uuid);
    $uuid=unmTransportUuid($uuid);if(unmNativeRecordForVm(UNM_BOOT_DIR,$uuid)!==null)throw new RuntimeException('Recovery-managed VMs are excluded from live migration.');
    if(unmActiveVmJob($uuid,unmJobs())!==null)throw new RuntimeException('Another migration/clone job already owns this VM.');
    foreach(glob(UNM_REPLICATIONS_DIR.'/*/policy.json')?:[] as $path)if((unmLoadJson($path)['vmUuid']??'')===$uuid)throw new RuntimeException('Replication policies must be removed before live migration.');
    foreach(glob(UNM_SEEDS_DIR.'/*/seed.json')?:[] as $path)if((unmLoadJson($path)['vmUuid']??'')===$uuid)throw new RuntimeException('Remove prepared Warm Moves before live migration.');
    $authority=unmLiveNativeRecord(UNM_BOOT_DIR,$uuid);if($authority&&$authority['state']!=='OWNER')throw new RuntimeException('An unresolved live handover fences this VM.');
    $state=unmLiveVmState($uuid);if(!in_array($state['state'],['running','idle'],true))throw new RuntimeException('Live migration requires a running VM.');
    // Preserve console authentication: an ordinary dump silently redacts VNC
    // credentials and would otherwise create an unauthenticated target console.
    // These XMLs remain private and cross only the authenticated encrypted pair.
    $live=unmLiveCommand(['virsh','dumpxml',$uuid,'--migratable','--security-info']);$persistent=unmLiveCommand(['virsh','dumpxml',$uuid,'--inactive','--security-info']);$spec=unmLiveInspectXml($live);$pending=unmLiveInspectXml($persistent);
    if($pending['machine']!==$spec['machine']||$pending['disks']!==$spec['disks']||$pending['vcpus']!==$spec['vcpus']||$pending['memoryKiB']!==$spec['memoryKiB']||$pending['firmware']!==$spec['firmware']||$pending['tpm']!==$spec['tpm'])throw new RuntimeException('Pending machine/disk/resource/firmware/TPM changes require a full stop/start before live migration.');
    $spec['firmwareEvidence']=unmLiveFirmwareEvidence($spec['firmware']);
    unmLiveTpmSourceEvidence($uuid,$live,$spec['tpm']);
    // Expand both active and pending CPU definitions on the source, not from a
    // cached host report. A next-boot CPU setting is not the running CPU ABI.
    // Do not strip nested virtualization from the contract while comparing it.
    // It must survive expansion and receive fresh validation on both hosts.
    $expanded=[];foreach([$spec['cpuXml'],$pending['cpuXml']] as $cpu)$expanded[]=unmCpuCompatibilityDefinition(unmLiveCommand(['virsh','hypervisor-cpu-baseline','/dev/stdin','--features','--virttype','kvm','--arch','x86_64','--machine',$spec['machine']],$cpu));
    if($expanded[0]!==$expanded[1])throw new RuntimeException('The selected CPU mode is not active yet. Fully stop/start the VM first.');
    $spec['cpuXml']=$expanded[0];unmLiveNestedCpu($spec['cpuXml']);
    $references=[];$all=trim(unmLiveCommand(['virsh','list','--all','--uuid']));foreach(preg_split('/\s+/',$all)?:[] as $other){if($other===''||$other===$uuid)continue;unmTransportUuid($other);foreach([true,false] as $inactive){$o=unmCpuCompatibilityXml(unmDomainXml($other,$inactive));foreach((new DOMXPath($o))->query('/domain/devices/disk/source/@file|/domain/devices/disk/source/@dev') as $file)$references[]=$file->nodeValue;}}
    $identities=[];foreach($spec['disks'] as &$disk){
        if(($disk['type']??'')==='zvol'){
            $disk['volume']=unmLiveZvolInspect($disk['path']);$disk['bytes']=$disk['volume']['volsize'];$k=unmLiveZvolKernel($disk['path']);$identity='block:'.$k['rdev'];if(isset($identities[$identity]))throw new RuntimeException('Multiple writable disks resolve to the same zvol.');$identities[$identity]=true;
            foreach($references as $ref){$s=@stat($ref);if($s&&($s['mode']&0170000)===0060000&&$s['rdev']===$k['rdev'])throw new RuntimeException('Another VM live or saved definition references this writable zvol.');if(str_starts_with($ref,'/dev/zvol/')&&in_array($ref,unmZvolPathAliases($disk['volume']['dataset']),true))throw new RuntimeException('Another VM references an alias of this writable zvol.');}
            continue;
        }
        $path=$disk['path'];unmTransportPath($path);if(is_link($path)||!is_file($path)||realpath($path)!==$path)throw new RuntimeException('Live disk path must be a real, independent file: '.$disk['target']);
        unmLiveFileSystem($path);$stat=stat($path);if(!$stat||$stat['nlink']!==1)throw new RuntimeException('Hard-linked disks are excluded from live migration.');
        $identity=$stat['dev'].':'.$stat['ino'];if(isset($identities[$identity]))throw new RuntimeException('Multiple disks resolve to the same file.');$identities[$identity]=true;
        foreach($references as $reference)if(unmLiveSameDisk($path,$stat,$reference))throw new RuntimeException('Another VM live or saved definition references writable disk '.$disk['target'].', including a user-share alias.');
        $info=json_decode(unmLiveCommand(['qemu-img','info','--force-share','--output=json',$path]),true,32,JSON_THROW_ON_ERROR);
        if(($info['format']??'')!==$disk['format']||isset($info['backing-filename'])||!empty($info['encrypted'])||!empty($info['snapshots'])||!is_int($info['virtual-size']??null)||$info['virtual-size']<1)throw new RuntimeException('Backing chains, internal snapshots and encrypted disks are excluded from this beta.');
        $disk['bytes']=$info['virtual-size'];
    }unset($disk);
    $spec['diskBytes']=$spec['disks'][0]['bytes'];$spec['totalDiskBytes']=unmLiveDiskTotal($spec['disks']);$spec['liveXml']=$live;$spec['persistentXml']=$persistent;$spec['qemu']=unmNativeQemu($uuid,$spec['vmName']);return $spec;
}
function unmLivePreview(string $uuid,string $peerId): array {
    $peer=unmPeer($peerId);if(($peer['pairingState']??'')!=='paired')throw new RuntimeException('Completed pairing is required.');unmAssertRemotePeerHost($peer['host']);
    $spec=unmLiveSource($uuid);$local=unmLivePlatform($spec['tpm']!==null);
    $caps=unmRemote($peer,'/usr/local/sbin/unmotion-agent capabilities',20);$cap=$caps['code']===0?json_decode($caps['stdout'],true):null;
    unmLiveAssertVersion(is_array($cap)?$cap:[],$peer['hostId'],true);
    try{$remote=unmLiveRpc($peer,'inspect',['xml'=>$spec['liveXml'],'firmware'=>$spec['firmwareEvidence'],'disks'=>$spec['disks']],45);}catch(Throwable $e){if(str_contains($e->getMessage(),'Unsupported unMotion RPC'))throw new RuntimeException('Live migration requires matching '.UNM_VERSION.' on both peers. Cold/Warm Move protocol-7 compatibility is unchanged.');throw $e;}
    unmLiveAssertVersion(is_array($remote['platform']??null)?$remote['platform']:[],$peer['hostId']);
    unmLiveTpmCompatible($spec['tpm'],$local['tpm'],$remote['platform']['tpm']??null);
    if(!unmLiveGeneration($local,$remote['platform']))throw new RuntimeException('The destination must be a confirmed same/newer CPU generation from the same vendor.');
    if($local['hypervisor']!==$remote['platform']['hypervisor'])throw new RuntimeException('Live migration requires matching qualified QEMU/libvirt versions.');
    return ['vmUuid'=>$uuid,'vmName'=>$spec['vmName'],'peerId'=>$peerId,'spec'=>$spec,'local'=>$local,'remote'=>$remote['platform'],'capacity'=>$remote['capacity'],'warnings'=>['The guest will briefly pause during handover; this is not a zero-latency guarantee.','Bridge names are checked, but equivalent physical networks and any in-guest VLAN configuration remain your responsibility.','Full virtual capacity plus allocation overhead and free-space headroom is budgeted. Zvols are fully reserved, file images remain sparse; concurrent external allocations can still consume free capacity.','The old source disk is retained; this beta never deletes it automatically.']];
}
function unmLiveTargetXml(string $xml,string|array $diskPath,?string $nvramPath=null,?string $tpmPath=null): string {
    $d=unmCpuCompatibilityXml($xml);$xp=new DOMXPath($d);$d->documentElement->removeAttribute('id');
    foreach(iterator_to_array($xp->query('/domain/devices/interface/target|/domain/devices/serial[@type="pty"]/source|/domain/devices/console[@type="pty"]/source|/domain/devices/channel[@type="unix"]/source|/domain/seclabel[@type="dynamic"]/label|/domain/seclabel[@type="dynamic"]/imagelabel')) as $n)$n->parentNode->removeChild($n);
    foreach($xp->query('/domain/devices/console') as $n)$n->removeAttribute('tty');
    foreach($xp->query('/domain/devices/channel/target') as $n)$n->removeAttribute('state');
    foreach($xp->query('/domain/devices/graphics') as $n){if(!in_array($n->getAttribute('type'),['vnc','spice'],true))throw new RuntimeException('Unsupported graphics device.');$n->setAttribute('autoport','yes');$n->setAttribute('port','-1');if($n->hasAttribute('websocket'))$n->setAttribute('websocket','-1');}
    $disks=unmLiveDisksXml($xp);if(is_string($diskPath)){if(count($disks)!==1)throw new RuntimeException('Every writable disk needs an exact destination mapping.');$diskPath=[$disks[0]['target']=>$diskPath];}
    if(count($diskPath)!==count($disks)||array_diff(array_keys($diskPath),array_column($disks,'target')))throw new RuntimeException('Destination disk mappings differ from the admitted disks.');
    foreach($xp->query('/domain/devices/disk[@device="disk"]') as $disk){$target=$xp->evaluate('string(target/@dev)',$disk);$source=$xp->query('./source',$disk)->item(0);if(!$source||!is_string($diskPath[$target]??null))throw new RuntimeException('Missing live disk mapping.');$source->setAttribute($disk->getAttribute('type')==='block'?'dev':'file',$diskPath[$target]);$source->removeAttribute('index');}
    if($nvramPath!==null){$nv=$xp->query('/domain/os/nvram')->item(0);if(!$nv)throw new RuntimeException('Unexpected destination NVRAM.');$nv->nodeValue=$nvramPath;}
    foreach(iterator_to_array($xp->query('/domain/devices/disk[@device="cdrom"]/source')) as $source)$source->parentNode->removeChild($source);
    if($tpmPath!==null){$uuid=trim($xp->evaluate('string(/domain/uuid)'));if(!unmLiveTpmPathAllowed($tpmPath,$uuid,true))throw new RuntimeException('Destination TPM path is not independently job/UUID scoped.');$backend=$xp->query('/domain/devices/tpm/backend')->item(0);if(!$backend)throw new RuntimeException('Unexpected destination TPM.');foreach(iterator_to_array($xp->query('./source',$backend)) as $source)$backend->removeChild($source);$source=$d->createElement('source');$source->setAttribute('type','dir');$source->setAttribute('path',$tpmPath);$backend->insertBefore($source,$backend->firstChild);$backend->setAttribute('persistent_state','yes');}
    $result=$d->saveXML();$spec=unmLiveInspectXml($result);unmTransportValidateDomainXml($result,$spec['vmUuid'],null,['zvolPathVersion'=>1]);return $result;
}
function unmLivePeerJob(array $peer,string $id,?string $token=null): array {
    $j=unmLiveJob($id);$expected=$j['role']==='source'?$j['destinationHostId']:$j['sourceHostId'];
    if(($peer['hostId']??'')!==$expected||($j[$j['role'].'HostId']??'')!==unmHostId())throw new RuntimeException('Live job belongs to another pairing.');
    if($token!==null&&(!preg_match('/\A[a-f0-9]{64}\z/D',$token)||!hash_equals($j['tokenHash'],hash('sha256',$token))))throw new RuntimeException('Invalid live job authorization.');return $j;
}
function unmLiveStatus(array $j): array {
    $record=unmLiveNativeRecord(UNM_BOOT_DIR,$j['vmUuid']);$authority=$record===null?null:($record['jobId']===$j['id']?$record['state']:'SUPERSEDED');
    return ['success'=>true,'jobId'=>$j['id'],'vmUuid'=>$j['vmUuid'],'vmName'=>$j['vmName'],'role'=>$j['role'],'jobState'=>$j['state'],'hostId'=>unmHostId(),'bootId'=>unmNativeBoot(),'vm'=>unmLiveVmState($j['vmUuid']),'authority'=>$authority,'transferComplete'=>unmLiveReceiveProven($j),'unreserved'=>($j['unreserved']??false)===true];
}
function unmLiveRemote(array $peer,array $a): array {
    if(($peer['pairingState']??'')!=='paired')throw new RuntimeException('Completed pairing is required.');$action=$a['action']??'';
    if(in_array($action,['cleanup-owner','cleanup-proof','cleanup-lease','cleanup-release'],true))return unmLiveCleanupRemote($peer,$a);
    if(in_array($action,['handback-ack','handback-proof'],true))return unmLiveHandbackRemote($peer,$a);
    if(in_array($action,['ordinary-accept','ordinary-proof','ordinary-commit'],true))return unmLiveOrdinaryRemote($peer,$a);
    $keys=match($action){'inspect'=>['action','xml','firmware','disks'],'reserve'=>['action','jobId','token','liveXml','persistentXml','sourcePlatform'],'status'=>['action','jobId'],'source-proof','reconcile-proof'=>['action','jobId','nonce'],'reauthorize'=>['action','jobId','token','nonce'],'commit'=>['action','jobId','token'],'abort'=>['action','jobId','token','vmUuid'],'abandon-unreserved'=>['action','jobId','token','vmUuid','nonce'],default=>throw new RuntimeException('Unknown live operation.')};
    if(array_diff(array_keys($a),$keys)||array_diff($keys,array_keys($a)))throw new RuntimeException('Invalid live operation fields.');
    if($action==='abandon-unreserved')return unmLiveAbandonUnreserved($peer,$a,true);
    if($action==='status'&&!file_exists(unmLiveJobPath((string)$a['jobId']))&&!is_link(unmLiveJobPath((string)$a['jobId'])))return ['success'=>true,'jobId'=>$a['jobId'],'hostId'=>unmHostId(),'notReserved'=>true]; // No authority/VM-absence claim yet.
    if($action==='inspect'){$s=unmLiveInspectXml((string)$a['xml']);$disks=unmLiveDisks(['disks'=>$a['disks']]);if(array_map('unmLiveDiskIdentity',$disks)!==$s['disks'])throw new RuntimeException('Capacity metadata does not match source XML.');unmLiveFirmwareDestination($s['firmware'],$a['firmware']);unmLiveTargetXml((string)$a['xml'],array_column($s['disks'],'path','target'));unmLiveValidateDestination($s);return ['success'=>true,'platform'=>unmLivePlatform($s['tpm']!==null),'capacity'=>unmLiveStoragePlan($disks)];}
    if($action==='reserve'){
        $admission=unmTransportAdmissionLock();
        try{
            $id=unmLiveId((string)$a['jobId']);if(file_exists(unmLiveJobPath($id)))throw new RuntimeException('Live job is already reserved.');
            $token=(string)$a['token'];if(!preg_match('/\A[a-f0-9]{64}\z/D',$token))throw new RuntimeException('Invalid job token.');
            $s=unmLiveInspectXml((string)$a['liveXml']);$p=unmLiveInspectXml((string)$a['persistentXml']);$vmLock=unmLiveVmLock($s['vmUuid']);
            unmLiveValidateDestination($s);$platform=unmLivePlatform($s['tpm']!==null);unmLiveAssertVersion(is_array($a['sourcePlatform'])?$a['sourcePlatform']:[],$peer['hostId']);if($a['sourcePlatform']['hypervisor']!==$platform['hypervisor']||!unmLiveGeneration($a['sourcePlatform'],$platform))throw new RuntimeException('Source/destination CPU or software compatibility changed.');
            unmLiveTpmCompatible($s['tpm'],$a['sourcePlatform']['tpm']??null,$platform['tpm']);
            if($s['vmUuid']!==$p['vmUuid']||$s['vmName']!==$p['vmName']||$s['disks']!==$p['disks']||$s['firmware']!==$p['firmware']||$s['tpm']!==$p['tpm'])throw new RuntimeException('Running and persistent destination identities disagree.');
            foreach(glob(unmLiveRoot().'/jobs/*/job.json')?:[] as $path){$old=unmLoadJson($path);if(!in_array($old['state']??'', ['COMPLETE','ABORTED','CANCELLED','FAILED','PROBE_FINISHED'],true))throw new RuntimeException('Another live migration needs to finish or be reconciled on this host.');}
            $reply=unmLiveRpc($peer,'source-proof',['jobId'=>$id,'nonce'=>bin2hex(random_bytes(16))]);
            if(($reply['authority']??'')!=='MOVING'||($reply['spec']['vmUuid']??'')!==$s['vmUuid']||array_map('unmLiveDiskIdentity',unmLiveDisks($reply['spec']??[]))!==$s['disks']||($reply['spec']['liveHash']??'')!==unmLiveXmlHash((string)$a['liveXml']))throw new RuntimeException('Fresh source ownership/VM proof failed.');
            unmLiveFirmwareDestination($s['firmware'],$reply['spec']['firmware']??null);
            $plan=unmLiveStoragePlan(unmLiveDisks($reply['spec']),$id);$disks=$plan['disks'];$disk=$disks[0]['path'];
            if($plan['imageDirectory']!==null){$dir=$plan['imageDirectory'].'/unmotion-'.$id;if(file_exists($dir)||is_link($dir))throw new RuntimeException('Destination image directory already exists.');}
            $nvram=$s['firmware']===null?null:'/etc/libvirt/qemu/nvram/'.$s['vmUuid'].'_VARS-unmotion-'.$id.'.fd';
            $tpmPath=$s['tpm']===null?null:unmLiveTpmDestination($s['vmUuid'],$id);
            $maps=array_column($disks,'path','target');$live=unmLiveTargetXml((string)$a['liveXml'],$maps,$nvram,$tpmPath);$persistent=unmLiveTargetXml((string)$a['persistentXml'],$maps,$nvram,$tpmPath);
            preg_match('/^MemAvailable:\s+(\d+) kB/m',(string)file_get_contents('/proc/meminfo'),$available);if($s['memoryKiB']>(int)($available[1]??0)*0.8)throw new RuntimeException('Insufficient destination memory headroom.');
            // Journal intent before any storage is created. No incoming QEMU
            // authority exists until every image/volume and firmware is ready.
            $intent=['schemaVersion'=>1,'id'=>$id,'vmUuid'=>$s['vmUuid'],'vmName'=>$s['vmName'],'role'=>'destination','peerId'=>$peer['id'],'sourceHostId'=>$peer['hostId'],'destinationHostId'=>unmHostId(),'bootId'=>unmNativeBoot(),'expiresAt'=>time()+21600,'state'=>'RESERVING','tokenHash'=>hash('sha256',$token),'runtime'=>unmLiveRuntime($id),'diskPath'=>$disk,'createdAt'=>gmdate('c'),'message'=>'Reserving storage; no incoming execution authority','capacity'=>$plan,'priorAuthority'=>unmLiveNativeRecord(UNM_BOOT_DIR,$s['vmUuid']),'disks'=>$plan['disks'],'persistentXml'=>$persistent,'cleanupTpmPath'=>$tpmPath];unmLiveStoreJob($intent);unmLiveCommand(['sync','-f',unmLiveJobPath($id)]);
            // Fence ordinary/native starts during potentially slow provisioning.
            // ABORTED grants no incoming permit. RECEIVING is written only after
            // every volume/image is ready and a second destination check passes.
            unmLiveValidateDestination($s);unmLiveAuthority($intent,'ABORTED',$disk);
            $disks=unmLiveStorageProvision($plan,static function(array $created)use(&$intent):void{unmLiveCleanupCapture($intent,$created['path']);});
            if($tpmPath!==null){unmLiveTpmReserve($tpmPath,$s['vmUuid']);unmLiveCleanupCapture($intent,$tpmPath);}
            if($nvram!==null){
                $nvdir=dirname($nvram);if(!is_dir($nvdir)||realpath($nvdir)!==$nvdir||is_link($nvram)||file_exists($nvram))throw new RuntimeException('Destination NVRAM storage is unavailable or already exists.');
                $fp=fopen($nvram,'x+b');if(!$fp)throw new RuntimeException('Cannot reserve destination NVRAM.');
                try{if(!ftruncate($fp,$reply['spec']['firmware']['nvramBytes'])||!fflush($fp)||!fsync($fp)||!chmod($nvram,0600))throw new RuntimeException('Cannot persist destination NVRAM reservation.');}finally{fclose($fp);}
                unmLiveCleanupCapture($intent,$nvram);
                // QEMU migrates pflash RAM and writes it into this backing file
                // on resume. Never replace a file behind an open QEMU fd.
            }
            $j=['schemaVersion'=>1,'id'=>$id,'vmUuid'=>$s['vmUuid'],'vmName'=>$s['vmName'],'role'=>'destination','peerId'=>$peer['id'],'sourceHostId'=>$peer['hostId'],'destinationHostId'=>unmHostId(),'bootId'=>unmNativeBoot(),'expiresAt'=>time()+21600,'state'=>'DESTINATION_READY','tokenHash'=>hash('sha256',$token),'runtime'=>unmLiveRuntime($id),'destinationXml'=>$live,'persistentXml'=>$persistent,'diskPath'=>$disk,'diskTarget'=>$s['diskTarget'],'disks'=>$disks,'createdAt'=>gmdate('c'),'message'=>'Reserved; waiting for encrypted disk/RAM transfer'];
            unmLiveValidateDestination($s);$j['capacity']=$plan;$j['cleanupManifest']=$intent['cleanupManifest']??null;$j['cleanupTpmPath']=$tpmPath;unmLivePrivateDir($j['runtime']);unmLiveStoreJob($j);unmLiveAuthority($j,'RECEIVING',$disk);
            return ['success'=>true,'destinationXml'=>$live,'persistentXml'=>$persistent,'diskPath'=>$disk,'disks'=>$disks];
        }finally{flock($admission,LOCK_UN);fclose($admission);}
    }
    if($action==='abort'&&!file_exists(unmLiveJobPath((string)$a['jobId']))&&!is_link(unmLiveJobPath((string)$a['jobId'])))return unmLiveAbandonUnreserved($peer,$a);
    $j=unmLivePeerJob($peer,(string)$a['jobId'],isset($a['token'])&&$action!=='reauthorize'?(string)$a['token']:null);
    if($action==='abort'&&$a['vmUuid']!==$j['vmUuid'])throw new RuntimeException('Abort VM identity differs from its job.');
    if($action==='abort'&&($j['unreserved']??false)===true&&$j['state']==='ABANDONING')return unmLiveAbandonUnreserved($peer,$a);
    if($action==='status')return unmLiveStatus($j);
    if($action==='abort'&&$j['role']==='destination'&&$j['state']==='RESERVING'){
        $lock=unmLiveVmLock($j['vmUuid']);$state=unmLiveVmState($j['vmUuid']);$prior=unmLiveNativeRecord(UNM_BOOT_DIR,$j['vmUuid']);if($state['state']!=='undefined'||!$state['qemuAbsent']||!unmLiveProvisioningAbortable($prior,$j))throw new RuntimeException('Cannot prove incomplete provisioning has no incoming execution authority.');unmLiveAuthority($j,'ABORTED',$j['diskPath']);$j['state']='ABORTED';$j['message']='Provisioning interrupted; source retained ownership, partial storage retained';unmLiveStoreJob($j);return ['success'=>true,'authority'=>'ABORTED','vm'=>$state];
    }
    if($action==='reconcile-proof'){
        unmLiveFenceProof();$permit=unmNativeJson(unmLiveRuntime($j['id']).'/reconcile-permit.json');$s=unmLiveStatus($j);unmLiveNoJob($j['vmUuid'],$s['vm']);
        if($j['role']!=='source'||($permit['nonce']??'')!==$a['nonce']||($permit['bootId']??'')!==unmNativeBoot()||(int)($permit['expiresAt']??0)<=time()||!in_array($s['authority'],['MOVING','RETIRED'],true)||unmProcessRecordedWorker(dirname(unmLiveJobPath($j['id'])).'/process.json','/usr/local/sbin/unmotion-live-worker',[$j['id']]))throw new RuntimeException('No fresh local reconciliation authorization.');
        return $s+['nonce'=>$a['nonce'],'tokenHash'=>$permit['tokenHash']];
    }
    if($action==='reauthorize'){
        if($j['role']!=='destination'||!preg_match('/\A[a-f0-9]{64}\z/D',(string)$a['token'])||!preg_match('/\A[a-f0-9]{32}\z/D',(string)$a['nonce']))throw new RuntimeException('Invalid reconciliation request.');
        $lock=unmLiveVmLock($j['vmUuid']);$s=unmLiveStatus($j);unmLiveNoJob($j['vmUuid'],$s['vm']);
        if(!in_array($s['authority'],['RECEIVING','ABORTED'],true)&&!($s['authority']==='OWNER'&&$j['state']==='COMMITTING'))throw new RuntimeException('Only an uncommitted/aborted incoming job can be reauthorized.');
        $proof=unmLiveRpc($peer,'reconcile-proof',['jobId'=>$j['id'],'nonce'=>$a['nonce']]);
        if(($proof['nonce']??'')!==$a['nonce']||!hash_equals((string)($proof['tokenHash']??''),hash('sha256',$a['token']))||!in_array($proof['authority']??'',['MOVING','RETIRED'],true))throw new RuntimeException('Fresh source reconciliation callback failed.');
        $j['tokenHash']=hash('sha256',$a['token']);unmLiveStoreJob($j);unmLiveCommand(['sync','-f',unmLiveJobPath($j['id'])]);return ['success'=>true];
    }
    if($action==='source-proof'){
        unmLiveFenceProof();
        if($j['role']!=='source'||!preg_match('/\A[a-f0-9]{32}\z/D',(string)$a['nonce']))throw new RuntimeException('Invalid source proof request.');$status=unmLiveStatus($j);$spec=$j['spec'];
        if($status['authority']==='MOVING'){
            if(!in_array($status['vm']['state'],['running','idle'],true)||unmNativeQemu($j['vmUuid'],$j['vmName'])!==$spec['qemu']||unmLiveXmlHash(unmLiveCommand(['virsh','dumpxml',$j['vmUuid'],'--migratable','--security-info']))!==unmLiveXmlHash($spec['liveXml']))throw new RuntimeException('Source VM changed after live migration admission.');
            foreach(unmLiveDisks($spec) as $disk)if(($disk['type']??'')==='zvol'&&unmLiveZvolInspect($disk['path'])!==($disk['volume']??null))throw new RuntimeException('Source ZFS volume identity/geometry changed after admission.');
        }
        $status['nonce']=$a['nonce'];$status['tokenHash']=$j['tokenHash'];$status['spec']=['vmUuid'=>$j['vmUuid'],'diskTarget'=>$spec['diskTarget'],'format'=>$spec['format'],'diskBytes'=>$spec['diskBytes'],'disks'=>unmLiveDisks($spec),'liveHash'=>unmLiveXmlHash($spec['liveXml']),'firmware'=>unmLiveFirmwareEvidence($spec['firmware']??null)];return $status;
    }
    if($j['role']!=='destination')throw new RuntimeException('Destination mutation required.');$lock=unmLiveVmLock($j['vmUuid']);$status=unmLiveStatus($j);
    if($action==='commit'){
        unmLiveFenceProof();
        if($status['authority']==='OWNER'&&$j['state']==='COMPLETE')return $status;
        if(empty($status['transferComplete']))throw new RuntimeException('The receiver has no current-boot proof of a successful native transfer. Keep it fenced; reconcile or abort the incomplete copy.');
        if(!in_array($status['vm']['state'],['paused','running','idle'],true)||!in_array($status['authority'],['RECEIVING','OWNER'],true))throw new RuntimeException('Destination is not an admitted paused receiver or committed owner.');
        $d=unmNativeDomain(unmDomainXml($j['vmUuid'],false));$q=unmNativeQemu($j['vmUuid'],$j['vmName']);$ad=unmNativeJson('/run/unmotion/lifecycle/live-admissions/'.$j['vmUuid'].'.json');
        foreach(unmLiveDisks($j) as $disk)if(($disk['type']??'')==='zvol'&&unmLiveZvolInspect($disk['path'])!==($disk['volume']??null))throw new RuntimeException('Destination ZFS volume identity/geometry changed before commit.');
        if(($ad['jobId']??'')!==$j['id']||($ad['qemu']??null)!==$q)throw new RuntimeException('Destination QEMU is not this job\'s admitted process.');
        $nonce=bin2hex(random_bytes(16));$proof=unmLiveRpc($peer,'source-proof',['jobId'=>$j['id'],'nonce'=>$nonce]);
        if(($proof['nonce']??'')!==$nonce||($proof['authority']??'')!=='RETIRED'||empty($proof['vm']['qemuAbsent']))throw new RuntimeException('Source is not provably stopped and durably retired.');
        // Persist intent before granting ownership. A crash immediately after
        // the authority write must still be recognized as an unfinished commit,
        // not as an unrenewable OWNER/DESTINATION_READY combination.
        $j['state']='COMMITTING';$j['message']='Source retired; committing destination ownership';unmLiveStoreJob($j);unmLiveCommand(['sync','-f',unmLiveJobPath($j['id'])]);
        unmLiveAuthority($j,'OWNER',$j['diskPath'],$q);
        if($status['vm']['state']==='paused')unmLiveCommand(['virsh','resume',$j['vmUuid']]);
        $status=unmLiveStatus($j);if(!in_array($status['vm']['state'],['running','idle'],true))throw new RuntimeException('Destination has not entered running state.');
        $j['state']='COMPLETE';$j['message']='Live migration committed; destination owns this VM';unmLiveStoreJob($j);return unmLiveStatus($j);
    }
    if(!in_array($status['authority'],['RECEIVING','ABORTED'],true))throw new RuntimeException('A committed destination cannot be aborted.');
    if(!$status['vm']['qemuAbsent']){
        if($status['vm']['state']!=='paused')throw new RuntimeException('A running or uncertain destination cannot be removed during abort.');
        $q=unmNativeQemu($j['vmUuid'],$j['vmName']);$ad=unmNativeJson('/run/unmotion/lifecycle/live-admissions/'.$j['vmUuid'].'.json');if(($ad['jobId']??'')!==$j['id']||($ad['qemu']??null)!==$q)throw new RuntimeException('Abort cannot prove this job owns the destination QEMU.');
        unmLiveCommand(['virsh','destroy',$j['vmUuid']]);
    }
    $status=unmLiveVmState($j['vmUuid']);if(!$status['qemuAbsent'])throw new RuntimeException('Destination QEMU is still present.');
    if($status['state']!=='undefined'){$binding=unmLiveNativeRecord(UNM_BOOT_DIR,$j['vmUuid']);unmLiveNativeBinding(unmNativeDomain(unmDomainXml($j['vmUuid'])),$binding);unmLiveUndefine($j['vmUuid'],$binding['firmware']??null,$binding['tpm']??null);}
    unmLiveAuthority($j,'ABORTED',$j['diskPath']);$j['state']='ABORTED';$j['message']='Incoming VM stopped and unregistered; partial disk retained';unmLiveStoreJob($j);return unmLiveStatus($j);
}
function unmLiveUpdate(array &$j,string $state,string $message): void {$j['state']=$state;$j['message']=$message;$j['updatedAt']=gmdate('c');unmLiveStoreJob($j);unmLiveLog($j['id'],$state.' '.$message);}
function unmLiveBandwidth(array $j,array $peer,string $token): float {
    $p=proc_open(unmLiveSshArgs($peer,['jobId'=>$j['id'],'token'=>$token],'live-benchmark'),[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!$p)throw new RuntimeException('Cannot start encrypted bandwidth sample.');
    $identity=unmProcessSnapshot(proc_get_status($p)['pid']);foreach($pipes as $pipe)stream_set_blocking($pipe,false);$start=microtime(true);$sent=0;$out='';$err='';$exit=-1;
    try{
        while(true){
            if(microtime(true)-$start>35||is_file($j['runtime'].'/cancel'))throw new RuntimeException('Bandwidth test cancelled or timed out.');
            $r=[$pipes[1],$pipes[2]];$w=is_resource($pipes[0])?[$pipes[0]]:[];$e=[];if(stream_select($r,$w,$e,1)===false)throw new RuntimeException('Bandwidth select failed.');
            foreach($r as $pipe){$part=fread($pipe,65536);if($part===false)throw new RuntimeException('Bandwidth response failed.');if($pipe===$pipes[1])$out.=$part;else$err.=$part;if(strlen($out)+strlen($err)>65536)throw new RuntimeException('Oversized bandwidth response.');}
            if($w){$n=fwrite($pipes[0],str_repeat("\0",min(262144,33554432-$sent)));if($n===false)throw new RuntimeException('Bandwidth sample write failed.');$sent+=$n;if($sent===33554432)fclose($pipes[0]);}
            $s=proc_get_status($p);if(!$s['running']){$exit=$s['exitcode'];$out.=stream_get_contents($pipes[1]);$err.=stream_get_contents($pipes[2]);break;}
        }
        $elapsed=microtime(true)-$start;if($exit!==0||$sent!==33554432||(json_decode($out,true)['bytes']??0)!==33554432)throw new RuntimeException('Encrypted bandwidth sample failed: '.trim($err));return 32/$elapsed;
    }finally{foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);if($identity)unmProcessStop($identity,1000);proc_close($p);}
}
function unmLiveDirtyRate(string $uuid): ?float {
    $r=unmRun(['virsh','domdirtyrate-calc',$uuid,'--seconds','3','--mode','page-sampling'],null,10);if($r['code']!==0)return null;
    $until=microtime(true)+6;do{$out=unmLiveCommand(['virsh','domstats',$uuid,'--dirtyrate']);if(preg_match('/dirtyrate\.megabytes_per_second=(\d+)/',$out,$m))return (float)$m[1];usleep(250000);}while(microtime(true)<$until);return null;
}
function unmLivePublicJobs(): array {
    $out=[];foreach(glob(unmLiveRoot().'/jobs/*/job.json')?:[] as $path){$j=unmLiveJob(basename(dirname($path)));$p=array_intersect_key($j,array_flip(['id','vmUuid','vmName','role','peerId','state','message','createdAt','updatedAt','bandwidthMiB','dirtyRateMiB','downtimeMs']));
        try{$r=unmLiveNativeRecord(UNM_BOOT_DIR,$j['vmUuid']);$p['canHandback']=($r['jobId']??'')===$j['id']&&in_array($j['state'],['COMPLETE','FAILED','CANCELLED'],true)&&($r['state']??'')==='OWNER'&&!unmLiveOrdinaryOwner($r);$p['canCleanup']=unmLiveCleanupTerminal($j['state'])&&in_array($r['state']??'',['RETIRED','ABORTED'],true)&&empty($j['unreserved']);}catch(Throwable){$p['canHandback']=false;$p['canCleanup']=false;}
        try{$cleanup=unmLiveCleanupRecord($j['id']);if($cleanup){$p['cleanup']=unmLiveCleanupPublic($cleanup);$p['cleanup']['workerActive']=unmProcessRecordedWorker(dirname($path).'/cleanup-process.json','/usr/local/sbin/unmotion-live-cleanup-worker',[$j['id']])!==null;$p['cleanup']['busy']=$p['cleanup']['workerActive']||$cleanup['state']==='PENDING'&&$cleanup['bootId']===unmNativeBoot()&&time()-strtotime($cleanup['updatedAt'])<30;$p['canCleanup']=true;}}catch(Throwable $e){$p['cleanup']=['state'=>'BLOCKED','message'=>$e->getMessage()];}
        if(($j['role']??'')==='source')$p['workerActive']=unmProcessRecordedWorker(dirname($path).'/process.json','/usr/local/sbin/unmotion-live-worker',[$j['id']])!==null;
        elseif($j['bootId']===unmNativeBoot()){
            $bytes=unmLiveReceivedBytes($j);
            $p['receivedMiB']=round($bytes/1048576,1);
            if($bytes>0&&$j['state']==='DESTINATION_READY'){$p['state']='RECEIVING';$p['message']='Receiving encrypted disk/RAM streams; destination execution remains fenced';}
        }
        if(($j['bootId']??'')===unmNativeBoot()&&is_file(unmLiveRuntime($j['id']).'/progress.json')){$sample=unmLoadJson(unmLiveRuntime($j['id']).'/progress.json');if(isset($sample['phase']))$p['transfer']=$sample;else $p['progress']=$sample['percent']??null;}$out[]=$p;
    }return $out;
}
function unmLiveNoJob(string $uuid,array $state): void {
    if($state['qemuAbsent'])return;$out=unmLiveCommand(['virsh','domjobinfo',$uuid]);if(!preg_match('/^Job type:\s+None\s*$/m',$out))throw new RuntimeException('A native libvirt job is still active; wait before reconciling.');
}
function unmLiveCreate(string $uuid,string $peerId): array {
    $lock=unmTransportAdmissionLock();try{
        $vmLock=unmLiveVmLock(unmTransportUuid($uuid));
        foreach(unmLivePublicJobs() as $other)if(!in_array($other['state']??'', ['COMPLETE','ABORTED','CANCELLED','FAILED','PROBE_FINISHED'],true))throw new RuntimeException('Finish or reconcile the existing live migration first.');
        $pre=unmLivePreview($uuid,$peerId);$id='live-'.bin2hex(random_bytes(12));$token=bin2hex(random_bytes(32));
        $j=['schemaVersion'=>1,'id'=>$id,'vmUuid'=>$uuid,'vmName'=>$pre['vmName'],'role'=>'source','peerId'=>$peerId,'sourceHostId'=>unmHostId(),'destinationHostId'=>$pre['remote']['hostId'],'bootId'=>unmNativeBoot(),'expiresAt'=>time()+21600,'tokenHash'=>hash('sha256',$token),'runtime'=>unmLiveRuntime($id),'spec'=>$pre['spec'],'sourcePlatform'=>$pre['local'],'createdAt'=>gmdate('c'),'state'=>'QUEUED','message'=>'Waiting for the live migration worker'];
        unmLivePrivateDir($j['runtime']);file_put_contents($j['runtime'].'/token',$token);chmod($j['runtime'].'/token',0600);unmLiveStoreJob($j);
        flock($vmLock,LOCK_UN);fclose($vmLock);
        flock($lock,LOCK_UN);fclose($lock);$lock=null;
        $r=unmRun('nohup setsid /usr/local/sbin/unmotion-live-worker '.escapeshellarg($id).' >>'.escapeshellarg(dirname(unmLiveJobPath($id)).'/launcher.log').' 2>&1 < /dev/null &',null,5);
        if($r['code']!==0)throw new RuntimeException('Unable to launch live migration worker.');return ['id'=>$id,'state'=>'QUEUED'];
    }finally{if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}}
}
function unmLiveReconcile(string $id): array {
    $j=unmLiveJob($id);$worker=unmProcessRecordedWorker(dirname(unmLiveJobPath($id)).'/process.json','/usr/local/sbin/unmotion-live-worker',[$id]);if($worker)throw new RuntimeException('The live worker is still active; let it finish or request cancellation.');
    if($j['role']!=='source')throw new RuntimeException('Reconcile from the source host.');
    if(in_array($j['state'],['COMPLETE','CANCELLED','ABORTED','FAILED'],true))return ['success'=>true];
    $reconcile=fopen('/var/lock/unmotion-live-reconcile-'.$id.'.lock','ce');if(!$reconcile||!flock($reconcile,LOCK_EX|LOCK_NB))throw new RuntimeException('This live job is already being reconciled.');
    unmLiveAssertWorkerGone($id);$peer=unmLivePeerForJob($j);$r=unmLiveNativeRecord(UNM_BOOT_DIR,$j['vmUuid']);
    // A lost worker can leave its native migration client alive. Withdraw only
    // this job's data-relay lease; do not infer VM absence or signal QEMU.
    // Older helpers may also retain inherited locks until their streams close.
    if(($r['jobId']??'')===$id)unmLiveUpdate($j,'ATTENTION','Worker stopped; withdrawing orphaned source transport before fresh reconciliation');
    $deadline=microtime(true)+15;$lock=null;
    do{try{$lock=unmLiveVmLock($j['vmUuid']);break;}catch(Throwable $busy){if(microtime(true)>=$deadline)throw new RuntimeException('Native transport cleanup still owns the VM lock. Keep both VMs fenced and retry reconciliation.');usleep(200000);}}while(true);
    if(($r['jobId']??'')!==$id&&in_array($j['state'],['QUEUED','PREFLIGHT'],true)){unmLiveUpdate($j,'CANCELLED','The worker never armed this VM; existing authority and VM state were preserved.');return ['success'=>true];}
    if(($r['jobId']??'')!==$id||!in_array($r['state'],['MOVING','RETIRED','OWNER'],true))throw new RuntimeException('The exact source handover authority is unavailable.');
    $state=unmLiveVmState($j['vmUuid']);unmLiveNoJob($j['vmUuid'],$state);$remote=unmLiveRpc($peer,'status',['jobId'=>$id]);unmLiveFenceProof();
    if(($remote['notReserved']??false)===true&&!isset($remote['authority'])||($remote['unreserved']??false)===true&&($remote['jobState']??'')==='ABANDONING'){
        if($r['state']!=='MOVING'||!in_array($state['state'],['running','idle','paused','shut off'],true))throw new RuntimeException('Only an unretired source can reconcile an unreserved receiver.');
        $q=$state['qemuAbsent']?null:unmNativeQemu($j['vmUuid'],$j['vmName']);if($q!==null&&$q!==$j['spec']['qemu'])throw new RuntimeException('The source QEMU is not the original admitted process.');
        $token=bin2hex(random_bytes(32));$nonce=bin2hex(random_bytes(16));unmLivePrivateDir(unmLiveRuntime($id));unmNativeWrite(unmLiveRuntime($id).'/reconcile-permit.json',['nonce'=>$nonce,'bootId'=>unmNativeBoot(),'tokenHash'=>hash('sha256',$token),'expiresAt'=>time()+30]);
        try{$remote=unmLiveRpc($peer,'abandon-unreserved',['jobId'=>$id,'token'=>$token,'vmUuid'=>$j['vmUuid'],'nonce'=>$nonce],45);}finally{if(is_file(unmLiveRuntime($id).'/reconcile-permit.json'))unlink(unmLiveRuntime($id).'/reconcile-permit.json');}
        if(($remote['jobId']??'')!==$id||($remote['hostId']??'')!==$j['destinationHostId']||($remote['authority']??'')!=='ABORTED'||empty($remote['vm']['qemuAbsent'])||($remote['vm']['state']??'')!=='undefined')throw new RuntimeException('Unreserved target absence was not authenticated.');
        $j['tokenHash']=hash('sha256',$token);unmLiveStoreJob($j);file_put_contents(unmLiveRuntime($id).'/token',$token);chmod(unmLiveRuntime($id).'/token',0600);unmLiveAuthority($j,'OWNER',$j['spec']['diskPath'],$q);if($state['state']==='paused')unmLiveCommand(['virsh','resume',$j['vmUuid']]);unmLiveUpdate($j,'CANCELLED','Unreserved receiver confirmed absent; source owns the VM. A stopped source was not booted.');return ['success'=>true];
    }
    if($r['state']==='OWNER'&&($remote['authority']??'')==='ABORTED'&&!empty($remote['vm']['qemuAbsent'])){unmLiveUpdate($j,'CANCELLED','Confirmed the previous rollback: source owns the VM and destination is absent');return ['success'=>true];}
    if($r['state']==='RETIRED'&&$state['qemuAbsent']&&($remote['authority']??'')==='OWNER'&&($remote['jobState']??'')==='COMPLETE'){
        if($state['state']==='shut off')unmLiveUndefine($j['vmUuid'],$r['firmware']??null,$r['tpm']??null);unmLiveUpdate($j,'COMPLETE','Destination ownership confirmed; its current power state was preserved');return ['success'=>true];
    }
    $token=bin2hex(random_bytes(32));$nonce=bin2hex(random_bytes(16));unmLivePrivateDir(unmLiveRuntime($id));
    unmNativeWrite(unmLiveRuntime($id).'/reconcile-permit.json',['nonce'=>$nonce,'bootId'=>unmNativeBoot(),'tokenHash'=>hash('sha256',$token),'expiresAt'=>time()+30]);
    try{unmLiveRpc($peer,'reauthorize',['jobId'=>$id,'token'=>$token,'nonce'=>$nonce],30);}finally{if(is_file(unmLiveRuntime($id).'/reconcile-permit.json'))unlink(unmLiveRuntime($id).'/reconcile-permit.json');}
    $j['tokenHash']=hash('sha256',$token);unmLiveStoreJob($j);file_put_contents(unmLiveRuntime($id).'/token',$token);chmod(unmLiveRuntime($id).'/token',0600);
    if($state['qemuAbsent']&&!empty($remote['transferComplete'])&&in_array($remote['vm']['state']??'',['paused','running','idle'],true)&&(($remote['authority']??'')==='RECEIVING'||(($remote['authority']??'')==='OWNER'&&($remote['jobState']??'')==='COMMITTING'))){
        unmLiveAuthority($j,'RETIRED',$j['spec']['diskPath']);unmLiveUpdate($j,'SOURCE_RETIRED','Source absence proven during reconciliation; committing the paused receiver');
        $remote=unmLiveRpc($peer,'commit',['jobId'=>$id,'token'=>$token],45);if(($remote['authority']??'')!=='OWNER')throw new RuntimeException('Destination ownership is not confirmed.');
        if($state['state']==='shut off')unmLiveUndefine($j['vmUuid'],$r['firmware']??null,$r['tpm']??null);unmLiveUpdate($j,'COMPLETE','Live handover reconciled; old source disk and NVRAM retained');return ['success'=>true];
    }
    if(in_array($state['state'],['running','idle','paused','shut off'],true)&&in_array($remote['authority']??'',['RECEIVING','ABORTED'],true)){
        $q=$state['qemuAbsent']?null:unmNativeQemu($j['vmUuid'],$j['vmName']);if($q!==null&&$q!==$j['spec']['qemu'])throw new RuntimeException('The source QEMU is not the original admitted process.');
        $remote=unmLiveRpc($peer,'abort',['jobId'=>$id,'token'=>$token,'vmUuid'=>$j['vmUuid']],45);if(($remote['authority']??'')!=='ABORTED'||empty($remote['vm']['qemuAbsent']))throw new RuntimeException('Destination absence is not confirmed.');
        unmLiveAuthority($j,'OWNER',$j['spec']['diskPath'],$q);if($state['state']==='paused')unmLiveCommand(['virsh','resume',$j['vmUuid']]);unmLiveUpdate($j,'CANCELLED','Destination aborted; source remains owner. A stopped source was not booted.');return ['success'=>true];
    }
    throw new RuntimeException('Cannot safely complete or roll back this handover. Neither VM will be started automatically.');
}
function unmLiveRunWorker(string $id): void {
    $host=fopen('/var/lock/unmotion-live.lock','ce');if(!$host)throw new RuntimeException('Live worker admission is unavailable.');if(!flock($host,LOCK_EX|LOCK_NB))throw new RuntimeException('Another live worker is active.',409);
    $j=unmLiveJob($id);if($j['role']!=='source'||$j['state']!=='QUEUED'||$j['bootId']!==unmNativeBoot())throw new RuntimeException('Live job cannot be launched in this state/boot.');
    $lock=unmLiveVmLock($j['vmUuid']);$relay=[];$migration=null;$pipes=[];$retired=false;$armed=false;$reserved=false;
    $identity=unmProcessSnapshot(getmypid());if(!$identity||!unmProcessCommandMatches($identity,'/usr/local/sbin/unmotion-live-worker',[$id]))throw new RuntimeException('Cannot prove live worker lifetime.');unmAtomicJson(dirname(unmLiveJobPath($id)).'/process.json',$identity);
    $peer=unmLivePeerForJob($j);$token=trim((string)file_get_contents($j['runtime'].'/token'));
    try{
        unmLiveUpdate($j,'PREFLIGHT','Freshly checking active CPU, machine, guest devices and destination');$pre=unmLivePreview($j['vmUuid'],$j['peerId']);$j['spec']=$pre['spec'];$j['sourcePlatform']=$pre['local'];
        if(is_file($j['runtime'].'/cancel'))throw new RuntimeException('Cancelled before migration.');
        if(($j['spec']['tpm']??null)!==null)$j['cleanupTpmPath']=unmTpmStatePath($j['vmUuid'],$j['spec']['persistentXml']);
        unmLiveCleanupCapture($j);unmLiveCommand(['virsh','autostart',$j['vmUuid'],'--disable']);unmLiveStoreJob($j);$armed=true;unmLiveAuthority($j,'MOVING',$j['spec']['diskPath'],$j['spec']['qemu']);
        unmLiveUpdate($j,'SOURCE_MOVING','Source native starts fenced; reserving an independent destination disk');
        $reserved=true;$r=unmLiveRpc($peer,'reserve',['jobId'=>$id,'token'=>$token,'liveXml'=>$j['spec']['liveXml'],'persistentXml'=>$j['spec']['persistentXml'],'sourcePlatform'=>$j['sourcePlatform']],60);
        foreach(['destinationXml','persistentXml','diskPath','disks'] as $key)$j[$key]=$r[$key];$j['diskTarget']=$j['spec']['diskTarget'];unmLiveStoreJob($j);
        unmLiveUpdate($j,'SOURCE_MOVING','Measuring the encrypted link and guest RAM dirty rate');$j['bandwidthMiB']=round(unmLiveBandwidth($j,$peer,$token),2);$j['dirtyRateMiB']=unmLiveDirtyRate($j['vmUuid']);
        unmLiveLog($id,'Measured encrypted throughput '.$j['bandwidthMiB'].' MiB/s; RAM dirty rate '.($j['dirtyRateMiB']??'unknown').' MiB/s. Fourfold headroom is recommended; concurrent disk/network load also matters.');
        if($j['dirtyRateMiB']!==null&&$j['dirtyRateMiB']>=$j['bandwidthMiB']*0.8)throw new RuntimeException('Insufficient measured bandwidth headroom to converge safely. Reduce guest activity and retry.');unmLiveStoreJob($j);
        if(is_file($j['runtime'].'/cancel'))throw new RuntimeException('Cancelled before native migration.');
        unmLivePrivateDir('/var/lib/unmotion');unmNativeWrite('/var/lib/unmotion/live-client-state.json',['jobId'=>$id,'bootId'=>unmNativeBoot()]);file_put_contents('/var/lib/unmotion/live-client-job',$id);chmod('/var/lib/unmotion/live-client-job',0600);
        foreach(['memory','disk'] as $channel){$p=proc_open(['/usr/local/sbin/unmotion-live-io','relay',$id,$channel],[0=>['file','/dev/null','r'],1=>['file',dirname(unmLiveJobPath($id)).'/relay.log','a'],2=>['file',dirname(unmLiveJobPath($id)).'/relay.log','a']],$unused);if(!$p)throw new RuntimeException('Cannot start live data relay.');$relay[]=['handle'=>$p,'identity'=>unmProcessSnapshot(proc_get_status($p)['pid'])];}
        $until=microtime(true)+5;while(!file_exists($j['runtime'].'/memory.sock')||!file_exists($j['runtime'].'/disk.sock')){if(microtime(true)>$until)throw new RuntimeException('Live relay did not become ready.');usleep(100000);}
        if(is_file($j['runtime'].'/cancel'))throw new RuntimeException('Cancelled before native migration.');
        file_put_contents($j['runtime'].'/destination.xml',$j['destinationXml']);file_put_contents($j['runtime'].'/persistent.xml',$j['persistentXml']);
        unmLiveUpdate($j,'COPYING','Copying disk and RAM while the source VM continues running');
        $args=['virsh','migrate',$j['vmUuid'],'--live','--persistent','--suspend','--copy-storage-all','--change-protection','--abort-on-error','--verbose','--desturi','qemu+ext:///system?command=/usr/local/sbin/unmotion-live-client','--migrateuri','unix://'.$j['runtime'].'/memory.sock','--disks-uri','unix://'.$j['runtime'].'/disk.sock','--migrate-disks',implode(',',array_column(unmLiveDisks($j['spec']),'target')),'--xml',$j['runtime'].'/destination.xml','--persistent-xml',$j['runtime'].'/persistent.xml'];
        $migration=proc_open($args,[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!$migration)throw new RuntimeException('Cannot launch native live migration.');foreach($pipes as $p)stream_set_blocking($p,false);
        $exit=null;$start=time();$cancelSent=false;$lastProgress=0;
        while(true){
            foreach($pipes as $p){$text=stream_get_contents($p);if($text!=='')unmLiveLog($id,trim(str_replace("\r","\n",$text)));}
            $process=proc_get_status($migration);if(!$process['running']){$exit=$process['exitcode'];break;}
            if(time()-$lastProgress>=3){$lastProgress=time();unmLiveProgressObserve($j);}
            if((is_file($j['runtime'].'/cancel')||time()-$start>UNM_LIVE_TRANSFER_SECONDS)&&!$cancelSent){
                if(unmNativeQemu($j['vmUuid'],$j['vmName'])!==$j['spec']['qemu'])throw new RuntimeException('Source QEMU changed while cancelling.');
                unmLiveCommand(['virsh','domjobabort',$j['vmUuid']]);$cancelSent=true;unmLiveLog($id,'Cancellation requested through libvirt; waiting for rollback.');
            }
            usleep(100000);
        }
        foreach($pipes as $p){$text=stream_get_contents($p);if($text!=='')unmLiveLog($id,trim($text));fclose($p);}$pipes=[];proc_close($migration);$migration=null;
        if($exit!==0)throw new RuntimeException('Native live migration failed ('.$exit.'); see live log.');
        $state=unmLiveVmState($j['vmUuid']);if(!$state['qemuAbsent']||$state['state']!=='shut off')throw new RuntimeException('Source QEMU has not provably stopped after migration.');
        unmLiveAuthority($j,'RETIRED',$j['spec']['diskPath']);$retired=true;unmLiveUpdate($j,'SOURCE_RETIRED','Source stopped and durably fenced; authorizing the paused destination');
        $r=unmLiveRpc($peer,'commit',['jobId'=>$id,'token'=>$token],45);if(($r['authority']??'')!=='OWNER'||!in_array($r['vm']['state']??'', ['running','idle'],true))throw new RuntimeException('Destination running ownership is not confirmed.');
        unmLiveUndefine($j['vmUuid'],$j['spec']['firmware']??null,$j['spec']['tpm']??null);unmLiveUpdate($j,'COMPLETE','Live migration complete; old source disk and NVRAM retained, source definition unregistered');
    }catch(Throwable $e){
        unmLiveLog($id,'Failure: '.$e->getMessage());$rolledBack=false;
        if($armed&&!$retired&&$migration===null){
            try{
                $s=unmLiveVmState($j['vmUuid']);$r=$reserved?unmLiveRpc($peer,'abort',['jobId'=>$id,'token'=>$token,'vmUuid'=>$j['vmUuid']],45):['authority'=>'ABORTED','vm'=>['qemuAbsent'=>true]];
                if(in_array($s['state'],['running','idle','shut off'],true)&&($r['authority']??'')==='ABORTED'&&!empty($r['vm']['qemuAbsent'])){unmLiveAuthority($j,'OWNER',$j['spec']['diskPath'],$s['qemuAbsent']?null:unmNativeQemu($j['vmUuid'],$j['vmName']));$rolledBack=true;}
            }catch(Throwable $rollback){unmLiveLog($id,'Rollback proof unavailable: '.$rollback->getMessage());}
        }
        $cancelled=is_file($j['runtime'].'/cancel');$message=$cancelled&&$rolledBack?'Live migration cancelled. Source remains owner; destination is stopped.':$e->getMessage().($rolledBack?' Source remains owner.':($armed?' Both-host review required; no automatic start.':' No handover authority was changed.'));unmLiveUpdate($j,(!$armed||$rolledBack)?($cancelled?'CANCELLED':'FAILED'):'ATTENTION',$message);
    }finally{
        // Do not kill a libvirt migration process to imply cancellation. If it
        // is still active, close streams, leave authority fenced and retain it.
        foreach($pipes as $p)if(is_resource($p))fclose($p);
        if($migration!==null)proc_close($migration);
        foreach($relay as $p){if($p['identity'])unmProcessStop($p['identity'],1000);proc_close($p['handle']);}
        if(is_file('/var/lib/unmotion/live-client-job')&&trim((string)file_get_contents('/var/lib/unmotion/live-client-job'))===$id)unlink('/var/lib/unmotion/live-client-job');
        flock($lock,LOCK_UN);fclose($lock);flock($host,LOCK_UN);fclose($host);
    }
}
function unmLiveLegacyInterlock(string $uuid,bool $receiving=false): void {
    $r=unmLiveNativeRecord(UNM_BOOT_DIR,$uuid);
    if($r!==null&&!unmLiveOrdinaryOwner($r)&&!($receiving&&($r['workflow']??'')==='ordinary'&&in_array($r['state'],['RETIRED','ABORTED','RECEIVING'],true)))throw new RuntimeException('This VM is live-managed; review powered-off hand-back before using ordinary workflows.');
    foreach(glob(unmLiveRoot().'/jobs/*/job.json')?:[] as $path){$j=unmLiveJob(basename(dirname($path)));if($j['vmUuid']===$uuid&&!in_array($j['state'],['COMPLETE','ABORTED','CANCELLED','FAILED','PROBE_FINISHED'],true))throw new RuntimeException('A queued or unfinished live migration already owns this VM.');}
}
