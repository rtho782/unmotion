<?php
// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
declare(strict_types=1);
require_once __DIR__.'/live-normal.php';

// Explicit inactive-copy reclamation. Never loaded by the synchronous hook.
const UNM_LIVE_CLEANUP_VERSION=1;
function unmLiveCleanupPath(string $id): string {return dirname(unmLiveJobPath($id)).'/cleanup.json';}
function unmLiveCleanupHash(mixed $value): string {return hash('sha256',json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));}
function unmLiveCleanupTerminal(string $state): bool {return in_array($state,['COMPLETE','ABORTED','CANCELLED','FAILED','PROBE_FINISHED'],true);}
function unmLiveCleanupObjectId(string $kind,string $path): string {return hash('sha256',$kind.'|'.$path);}

/** Validate path shape independently of whether an already-deleted object exists. */
function unmLiveCleanupObjectPath(string $kind,string $path,string $uuid): void {
    if(!in_array($kind,['image','zvol','nvram','tpm'],true)||strlen($path)>2048||preg_match('~[\x00-\x1f\x7f]|//|(?:\A|/)\.{1,2}(?:/|\z)~D',$path))throw new RuntimeException('Invalid retained-copy object path.');
    if($kind==='image'&&(!str_starts_with($path,'/mnt/')||str_ends_with($path,'/')||preg_match('~\A/mnt/user0?(?:/|\z)~D',$path)))throw new RuntimeException('Retained images require direct local storage.');
    if($kind==='zvol'){unmLiveDiskIdentity(['path'=>$path,'target'=>'vda','format'=>'raw','type'=>'zvol']);}
    if($kind==='nvram'&&!preg_match('~\A/etc/libvirt/qemu/nvram/'.preg_quote($uuid,'~').'_[A-Za-z0-9_.-]{1,160}\.fd\z~D',$path))throw new RuntimeException('Retained NVRAM is not UUID-scoped libvirt state.');
    if($kind==='tpm'&&!unmTpmPathAllowed($path,$uuid))throw new RuntimeException('Retained TPM is not UUID-scoped libvirt state.');
}
/** ZFS st_dev is an import-order number, not a persistent filesystem identity.
 * Bind its dataset GUID + inode instead. Never upgrade an old numeric identity
 * from a pathname after the fact. Other filesystems retain strict device checks.
 */
function unmLiveCleanupZfsFileKey(string $path,?callable $command=null): ?string {
    if(!str_starts_with($path,'/mnt/'))return null;$command??='unmLiveCommand';
    $m=json_decode($command(['findmnt','-J','-o','FSTYPE,SOURCE','--target',$path]),true,16,JSON_THROW_ON_ERROR);
    if(!is_array($m['filesystems']??null)||count($m['filesystems'])!==1)throw new RuntimeException('Cannot prove retained filesystem identity.');$row=$m['filesystems'][0];
    if(($row['fstype']??null)!=='zfs')return null;$ds=$row['source']??'';
    if(!is_string($ds)||$ds===''||$ds!==trim($ds)||str_starts_with($ds,'/')||str_ends_with($ds,'/')||preg_match('~[\x00-\x1f\x7f@#]|(?:\A|/)\.{1,2}(?:/|\z)|//~D',$ds))throw new RuntimeException('Invalid retained ZFS filesystem.');
    $props=[];foreach(explode("\n",trim($command(['zfs','get','-Hp','-o','property,value','type,guid',$ds]))) as $line){$kv=explode("\t",$line,2);if(count($kv)!==2||isset($props[$kv[0]]))throw new RuntimeException('Invalid retained filesystem properties.');$props[$kv[0]]=$kv[1];}
    if(($props['type']??'')!=='filesystem'||!preg_match('/\A[1-9][0-9]{0,19}\z/D',$props['guid']??''))throw new RuntimeException('Retained ZFS filesystem GUID unavailable.');return 'zfs:'.$ds.':'.$props['guid'];
}
function unmLiveCleanupFileIdentity(string $path,bool $directory=false): array {
    for($p=$path;$p!==dirname($p);$p=dirname($p))if(is_link($p))throw new RuntimeException('Retained-copy path contains a symbolic link.');
    clearstatcache(true,$path);$s=@lstat($path);
    if(!$s||realpath($path)!==$path||($directory?!is_dir($path):!is_file($path))||(!$directory&&$s['nlink']!==1))throw new RuntimeException('Retained-copy object is missing, redirected or hard-linked.');
    $key=unmLiveCleanupZfsFileKey($path);clearstatcache(true,$path);$again=@lstat($path);if(!$again||$again['dev']!==$s['dev']||$again['ino']!==$s['ino'])throw new RuntimeException('Retained filesystem changed during identity check.');
    return ['device'=>$key??(string)$s['dev'],'inode'=>(string)$s['ino']];
}
function unmLiveCleanupExpected(array $j,bool $allowUnknownTpm=false): array {
    $s=$j['role']==='source'?($j['spec']??[]):$j;$xml=$j['role']==='source'?($s['persistentXml']??null):($j['persistentXml']??null);
    if(!is_string($xml))throw new RuntimeException('Incomplete live job has no exact storage/XML contract.');
    $parsed=unmLiveInspectXml($xml);if($parsed['vmUuid']!==$j['vmUuid'])throw new RuntimeException('Retained-copy XML belongs to another VM.');
    $disks=unmLiveDisks($s);if(array_map('unmLiveDiskIdentity',$disks)!==$parsed['disks'])throw new RuntimeException('Retained-copy XML and disk records disagree.');$out=[];
    foreach($disks as $disk){$kind=($disk['type']??'')==='zvol'?'zvol':'image';$out[]=['kind'=>$kind,'path'=>$disk['path'],'disk'=>unmLiveDiskIdentity($disk)+['bytes'=>$disk['bytes']]];}
    if($parsed['firmware']!==null)$out[]=['kind'=>'nvram','path'=>$parsed['firmware']['nvramPath']];
    if($parsed['tpm']!==null){$path=$j['cleanupTpmPath']??$parsed['tpm']['path'];if(!is_string($path)){if(!$allowUnknownTpm)throw new RuntimeException('Older TPM job lacks a recorded exact state directory; its data is retained.');}else $out[]=['kind'=>'tpm','path'=>$path];}
    foreach($out as &$o){unmLiveCleanupObjectPath($o['kind'],$o['path'],$j['vmUuid']);$o['id']=unmLiveCleanupObjectId($o['kind'],$o['path']);}unset($o);
    if(count(array_unique(array_column($out,'id')))!==count($out))throw new RuntimeException('Duplicate retained-copy objects.');return $out;
}
/** Record an admitted identity, not inferred ownership from a future filename. */
function unmLiveCleanupCapture(array &$j,?string $only=null): void {
    $m=$j['cleanupManifest']??['schemaVersion'=>1,'jobId'=>$j['id'],'vmUuid'=>$j['vmUuid'],'role'=>$j['role'],'objects'=>[]];
    foreach(unmLiveCleanupExpected($j) as $o){if($only!==null&&$only!==$o['path'])continue;if(isset($m['objects'][$o['id']]))continue;
        $o['identity']=$o['kind']==='zvol'?unmLiveZvolInspect($o['path']):unmLiveCleanupFileIdentity($o['path'],$o['kind']==='tpm');
        if($o['kind']==='zvol'&&$j['role']==='source')foreach(unmLiveDisks($j['spec']) as $disk)if($disk['path']===$o['path']&&$o['identity']!==($disk['volume']??null))throw new RuntimeException('Retained zvol admission identity changed.');$m['objects'][$o['id']]=$o;
    }
    $j['cleanupManifest']=$m;unmLiveStoreJob($j);unmLiveCommand(['sync','-f',unmLiveJobPath($j['id'])]);
}
function unmLiveCleanupManifest(array $j,?array &$retained=null): array {
    $retained=[];
    $m=$j['cleanupManifest']??null;
    if($m!==null&&(!is_array($m)||($m['schemaVersion']??null)!==1||($m['jobId']??'')!==$j['id']||($m['vmUuid']??'')!==$j['vmUuid']||($m['role']??'')!==$j['role']||!is_array($m['objects']??null)))throw new RuntimeException('Corrupt retained-copy identity manifest; no cleanup is authorized.');
    $expected=unmLiveCleanupExpected($j,true);$out=[];$seen=[];$spec=$j['role']==='source'?$j['spec']:$j;
    foreach($expected as $o){$saved=$m['objects'][$o['id']]??null;$seen[$o['id']]=true;
        // Released zvol jobs already journal exact ZFS GUID/geometry. That is
        // sufficient identity, unlike an old file/TPM pathname by itself.
        if($saved===null&&$o['kind']==='zvol')foreach(unmLiveDisks($spec) as $disk)if($disk['path']===$o['path']&&isset($disk['volume']))$saved=$o+['identity'=>unmLiveVolumeContract($disk['volume'])];
        if($saved===null){$retained[]=['kind'=>$o['kind'],'path'=>$o['path'],'reason'=>'No admitted file/directory identity was recorded; retained, not adopted by filename.'];continue;}
        if(!is_array($saved)||!is_array($saved['identity']??null))throw new RuntimeException('Invalid cleanup identity.');$identity=$saved['identity'];unset($saved['identity']);if($saved!==$o)throw new RuntimeException('Retained-copy identity manifest changed.');$out[]=$o+['identity'=>$identity];
    }
    if(array_diff(array_keys($m['objects']??[]),array_keys($seen)))throw new RuntimeException('Unknown cleanup manifest objects.');
    $parsed=unmLiveInspectXml($spec['persistentXml']);if($parsed['tpm']!==null&&!is_string($j['cleanupTpmPath']??$parsed['tpm']['path']))$retained[]=['kind'=>'tpm','path'=>null,'reason'=>'Older job has no unambiguous recorded TPM directory; all such state is retained.'];return $out;
}
function unmLiveCleanupRoleGuard(array $j,?array $r,array $vm): void {
    if(!unmLiveCleanupTerminal((string)($j['state']??''))||$r===null||($r['vmUuid']??'')!==$j['vmUuid']||!in_array($r['state']??'',['RETIRED','ABORTED'],true)||($r['workflow']??'')==='ordinary-pending'||($vm['state']??'')!=='undefined'||($vm['qemuAbsent']??null)!==true)throw new RuntimeException('Cleanup requires a settled, undefined non-owner copy with its start fence intact. Reconcile unfinished work first.');
}
function unmLiveCleanupLeasePath(string $uuid): string {return unmLiveRoot().'/cleanup-leases/'.unmTransportUuid($uuid).'.json';}
function unmLiveCleanupLeaseRead(string $uuid): ?array {
    $path=unmLiveCleanupLeasePath($uuid);if(!file_exists($path)&&!is_link($path))return null;if(is_link($path))throw new RuntimeException('Redirected retained-copy cleanup lease.');$l=unmNativeJson($path);$uuidPattern='/\A[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\z/D';
    if(($l['schemaVersion']??null)!==1||($l['vmUuid']??'')!==$uuid||!is_int($l['expiresAt']??null)||$l['expiresAt']<0||!preg_match($uuidPattern,(string)($l['bootId']??''))||!preg_match($uuidPattern,(string)($l['peerHostId']??''))||!preg_match('/\Alive-[a-f0-9]{24}\z/D',(string)($l['jobId']??''))||!preg_match('/\A[a-f0-9]{32}\z/D',(string)($l['nonce']??'')))throw new RuntimeException('Corrupt retained-copy cleanup lease blocks ownership changes.');
    foreach(['ownerHash','planHash'] as $k)if(!preg_match('/\A[a-f0-9]{64}\z/D',(string)($l[$k]??'')))throw new RuntimeException('Invalid cleanup lease authority binding.');if(!is_int($l['monotonicExpiresNs']??null)||$l['monotonicExpiresNs']<0)throw new RuntimeException('Invalid monotonic cleanup lease.');return $l;
}
function unmLiveCleanupLeaseActive(array $l): bool {return $l['bootId']===unmNativeBoot()&&$l['expiresAt']!==0&&$l['monotonicExpiresNs']>hrtime(true);}
function unmLiveCleanupInterlock(string $uuid): void {
    $l=unmLiveCleanupLeaseRead($uuid);if($l===null)return;
    if(unmLiveCleanupLeaseActive($l))throw new RuntimeException('A peer is reclaiming an inactive copy of this VM. Retry after its short cleanup lease ends.');
}
/** Only current, exact, live-ledger ownership can endorse deleting another copy. */
function unmLiveCleanupOwnerProof(array $j): array {
    $normal=unmLiveNormalCleanupProof($j);if($normal!==null)return $normal;
    unmLiveFenceProof();$r=unmLiveNativeRecord(UNM_BOOT_DIR,$j['vmUuid']);
    if(!$r||$r['state']!=='OWNER'||isset($r['workflow'])||unmNativeRecordForVm(UNM_BOOT_DIR,$j['vmUuid'])!==null)throw new RuntimeException('The paired host must prove current live ownership, not recovery, hand-back or an unresolved transfer.');
    $s=unmLiveVmState($j['vmUuid']);if(!in_array($s['state'],['running','idle','shut off'],true))throw new RuntimeException('The current owning VM is missing or uncertain.');
    $xml=unmLiveCommand(['virsh','dumpxml',$j['vmUuid'],'--inactive','--security-info']);$d=unmNativeDomain($xml);unmLiveNativeBinding($d,$r);unmLiveNoJob($j['vmUuid'],$s);
    foreach(unmLiveDisks($r) as $disk){if(($disk['type']??'')==='zvol'){if(unmLiveZvolInspect($disk['path'])!==($disk['volume']??null))throw new RuntimeException('The owning VM volume changed.');}else unmLiveCleanupFileIdentity($disk['path']);}
    if(($r['firmware']??null)!==null)unmLiveCleanupFileIdentity($r['firmware']['nvramPath']);
    if(($r['tpm']??null)!==null){$path=$r['tpm']['path']??unmTpmStatePath($j['vmUuid'],$xml);unmLiveCleanupFileIdentity($path,true);}
    return ['hostId'=>unmHostId(),'bootId'=>unmNativeBoot(),'vmUuid'=>$j['vmUuid'],'authorityHash'=>unmLiveCleanupHash($r),'ownerJobId'=>$r['jobId']];
}
function unmLiveCleanupLocalGuard(array $j): array {
    unmLiveFenceProof();$r=unmLiveNativeRecord(UNM_BOOT_DIR,$j['vmUuid']);$s=unmLiveVmState($j['vmUuid']);unmLiveCleanupRoleGuard($j,$r,$s);
    if(unmNativeRecordForVm(UNM_BOOT_DIR,$j['vmUuid'])!==null||unmActiveVmJob($j['vmUuid'],unmJobs())!==null)throw new RuntimeException('Recovery or another ordinary job owns this VM.');
    foreach(['seeds/*/seed.json','replications/*/policy.json'] as $glob)foreach(glob(UNM_BOOT_DIR.'/'.$glob)?:[] as $path)if((unmLoadJson($path)['vmUuid']??'')===$j['vmUuid'])throw new RuntimeException('Retained preparations/replication must be settled before live-copy cleanup.');
    foreach(unmLivePublicJobs() as $other)if(($other['vmUuid']??'')===$j['vmUuid']&&!unmLiveCleanupTerminal((string)$other['state']))throw new RuntimeException('Another live handover is unresolved.');
    unmLiveAssertWorkerGone($j['id']);return $r;
}
/** Fresh references include inactive and active XML, aliases and backing sources. */
function unmLiveCleanupReferences(): array {
    $paths=[];$ids=preg_split('/\s+/',trim(unmLiveCommand(['virsh','list','--all','--uuid'])),-1,PREG_SPLIT_NO_EMPTY);
    foreach($ids as $uuid){unmTransportUuid($uuid);foreach([true,false] as $inactive){$d=unmCpuCompatibilityXml(unmDomainXml($uuid,$inactive));$xp=new DOMXPath($d);foreach($xp->query('//@file|//@dev|//@path|/domain/os/nvram/text()') as $n){$v=trim($n->nodeValue);if(str_starts_with($v,'/'))$paths[]=$v;}}}
    $swaps=@file_get_contents('/proc/swaps');if(!is_string($swaps))throw new RuntimeException('Kernel swap references are unavailable.');$paths=array_merge($paths,unmLiveCleanupKernelPaths(unmLiveCommand(['losetup','--json','--output','NAME,BACK-FILE']),$swaps));return array_values(array_unique($paths));
}
function unmLiveCleanupKernelPaths(string $loopJson,string $swaps): array {
    $r=json_decode($loopJson,true,32,JSON_THROW_ON_ERROR);if(!is_array($r)||!is_array($r['loopdevices']??null)||!array_is_list($r['loopdevices']))throw new RuntimeException('Loop-device reference inventory is unavailable.');$paths=[];
    foreach($r['loopdevices'] as $loop){$p=$loop['back-file']??null;if(!is_string($p)||!str_starts_with($p,'/'))throw new RuntimeException('Invalid loop backing-file reference.');$paths[]=preg_replace('/ \(deleted\)\z/D','',$p);}
    $lines=preg_split('/\R/',trim($swaps));if(!preg_match('/\AFilename\s/',$lines[0]??''))throw new RuntimeException('Invalid kernel swap inventory.');foreach(array_slice($lines,1) as $line){if(trim($line)==='')continue;$p=preg_split('/\s+/',trim($line));if(count($p)!==5||!str_starts_with($p[0],'/'))throw new RuntimeException('Invalid kernel swap reference.');$paths[]=preg_replace_callback('/\\\\([0-7]{3})/',static fn($m)=>chr(octdec($m[1])),$p[0]);}return array_values(array_unique($paths));
}
function unmLiveCleanupMountedGuard(array $devices,string $mountInfo,array $holders): void {
    if($holders)throw new RuntimeException('A kernel device holder still uses the retained zvol.');if($mountInfo==='')throw new RuntimeException('Kernel mount inventory unavailable.');
    foreach(preg_split('/\R/',trim($mountInfo)) as $line){$p=explode(' ',$line);if(count($p)<6||!preg_match('/\A[0-9]+:[0-9]+\z/D',$p[2]??''))throw new RuntimeException('Invalid kernel mount inventory.');if(in_array($p[2],$devices,true))throw new RuntimeException('A retained zvol or partition is mounted on the host.');}
}
function unmLiveCleanupKernelGuard(array $objects): void {
    $mounts=@file_get_contents('/proc/self/mountinfo');if(!is_string($mounts))throw new RuntimeException('Kernel mount references unavailable.');
    foreach($objects as $o){if($o['kind']!=='zvol')continue;$k=unmLiveZvolKernel($o['path']);$name=basename($k['node']);$root='/sys/class/block/'.$name;$devices=[];$holders=[];
        foreach(array_merge([$root],glob($root.'/'.$name.'p[0-9]*')?:[]) as $sys){$dev=trim((string)@file_get_contents($sys.'/dev'));if(!preg_match('/\A[0-9]+:[0-9]+\z/D',$dev))throw new RuntimeException('Partition identity unavailable before cleanup.');$devices[]=$dev;$h=@scandir($sys.'/holders');if($h===false)throw new RuntimeException('Kernel device holders unavailable.');$holders=array_merge($holders,array_values(array_diff($h,['.','..'])));}
        unmLiveCleanupMountedGuard($devices,$mounts,$holders);
    }
}
function unmLiveCleanupReferenceGuard(array $o,array $paths): void {
    $path=$o['path'];$stat=@stat($path);
    foreach($paths as $ref){$resolved=realpath($ref);$check=[$ref];if($resolved!==false)$check[]=$resolved;
        if(str_starts_with($ref,'/mnt/user/')||str_starts_with($ref,'/mnt/user0/')){$rr=unmResolveUserSharePath(str_starts_with($ref,'/mnt/user0/')?'/mnt/user/'.substr($ref,11):$ref);$check=array_merge($check,$rr['candidates']??[],[$rr['physicalPath']??'']);}
        foreach($check as $p)if($p===$path||$o['kind']==='tpm'&&str_starts_with($p,$path.'/'))throw new RuntimeException('A VM or kernel reference still uses retained data: '.$path);
        $s=@stat($ref);if($stat&&$s&&($o['kind']==='zvol'?($s['mode']&0170000)===0060000&&$s['rdev']===$stat['rdev']:$s['dev']===$stat['dev']&&$s['ino']===$stat['ino']))throw new RuntimeException('A VM or kernel reference uses an alias of retained data: '.$path);
    }
}
function unmLiveCleanupOpenGuard(array $objects): void {
    $names=[];$inodes=[];$blocks=[];$tpm=[];foreach($objects as $o){$names[$o['path']]=true;if($o['kind']==='zvol'){$s=@stat($o['path']);if($s)$blocks[(string)$s['rdev']]=true;}else{$s=@stat($o['path']);if($s)$inodes[$s['dev'].':'.$s['ino']]=true;if($o['kind']==='tpm')$tpm[]=$o['path'];foreach($o['observed']['evidence']['entries']??[] as $relative=>$entry){$s=@stat($o['path'].($relative!==''?'/'.$relative:''));if($s)$inodes[$s['dev'].':'.$s['ino']]=true;}}}
    foreach(glob('/proc/[0-9]*/cmdline')?:[] as $cmd){$exe=(string)@readlink(dirname($cmd).'/exe');if(basename($exe)!=='swtpm')continue;$args=explode("\0",rtrim((string)@file_get_contents($cmd),"\0"));$i=array_search('--tpmstate',$args,true);if($i!==false&&preg_match('~\A(?:dir=|backend-uri=dir://)([^,]+)~',(string)($args[$i+1]??''),$m))foreach($objects as $o)if($o['kind']==='tpm'&&($m[1]===$o['path']||str_starts_with($m[1],$o['path'].'/')))throw new RuntimeException('A software TPM process still owns retained state.');}
    foreach(glob('/proc/[0-9]*/fd')?:[] as $dir){$pid=(int)basename(dirname($dir));if($pid===getmypid())continue;$fds=@scandir($dir);if($fds===false){if(is_dir($dir))throw new RuntimeException('Unable to inspect process file ownership before cleanup.');continue;}
        foreach($fds as $fd){if(!ctype_digit($fd))continue;$name=$dir.'/'.$fd;$target=@readlink($name);if($target===false)continue;$s=@stat($name);
            if(isset($names[$target]))throw new RuntimeException('A process still has retained storage open: '.$target);foreach($tpm as $path)if(str_starts_with($target,$path.'/'))throw new RuntimeException('A process still has retained TPM state open.');if($s&&(($s['mode']&0170000)===0060000&&isset($blocks[(string)$s['rdev']])||isset($inodes[$s['dev'].':'.$s['ino']])))throw new RuntimeException('A process still uses a retained-copy identity.');
        }
    }
}
function unmLiveCleanupZvolGuard(array $o,?callable $command=null,?callable $inspect=null): void {
    $command??='unmLiveCommand';$inspect??='unmLiveZvolInspect';
    if($inspect($o['path'])!==$o['identity'])throw new RuntimeException('Retained zvol GUID or geometry changed.');$ds=$o['identity']['dataset'];
    $names=preg_split('/\R/',trim($command(['zfs','list','-H','-o','name','-r',$ds])),-1,PREG_SPLIT_NO_EMPTY);if($names!==[$ds])throw new RuntimeException('A child dataset blocks retained zvol deletion.');
    if(trim($command(['zfs','list','-H','-t','snapshot','-o','name','-r',$ds]))!=='')throw new RuntimeException('Snapshots, holds or dependent clones must be resolved before deleting this retained zvol.');
    if(trim($command(['zfs','get','-Hp','-o','value','receive_resume_token',$ds]))!=='-')throw new RuntimeException('A resumable receive is retained; live-copy cleanup cannot discard it.');
}
/** Small host state is content-bound; large images are inode-bound, never followed. */
function unmLiveCleanupAbsentState(array $o): bool {
    if(!in_array($o['kind'],['tpm','nvram'],true))return false;
    // Libvirt may remove an aborted incoming TPM. Absence is an observation,
    // never permission to unlink a later replacement or adopt a new identity.
    clearstatcache();for($p=$o['path'];$p!==dirname($p);$p=dirname($p))if(is_link($p))throw new RuntimeException('Absent host-state path was redirected.');
    if(@lstat($o['path'])!==false)return false;
    $missing=$o['path'];for($p=dirname($missing);!file_exists($p);$p=dirname($p)){$missing=$p;if($p===dirname($p))throw new RuntimeException('Cannot prove host-state parent.');}
    if(!is_dir($p)||realpath($p)!==$p||!is_readable($p))throw new RuntimeException('Cannot prove absent host-state inventory.');
    $entries=scandir($p);if($entries===false||in_array(basename($missing),$entries,true))throw new RuntimeException('Host-state absence could not be confirmed from its parent inventory.');
    return true;
}
function unmLiveCleanupInspect(array $j,array $o): array {
    if($o['kind']==='zvol'){unmLiveCleanupZvolGuard($o);$used=trim(unmLiveCommand(['zfs','get','-Hp','-o','value','used',$o['identity']['dataset']]));if(!ctype_digit($used))throw new RuntimeException('Retained zvol allocation unavailable.');return ['identity'=>$o['identity'],'allocatedBytes'=>(int)$used,'logicalBytes'=>$o['identity']['volsize']];}
    if(unmLiveCleanupAbsentState($o))return ['identity'=>$o['identity'],'alreadyAbsent'=>true,'allocatedBytes'=>0,'logicalBytes'=>0];
    if(unmLiveCleanupFileIdentity($o['path'],$o['kind']==='tpm')!==$o['identity'])throw new RuntimeException('Retained file/directory identity changed.');unmLiveFileSystem($o['path']);unmHostStateNoNestedMounts($o['path']);
    if($o['kind']==='tpm'||$o['kind']==='nvram'){$e=unmHostStateEvidence($o['path'],$o['kind'],$j['vmUuid']);if(empty($e['cleanupAllowed']))throw new RuntimeException('Host-state path is not cleanup-safe.');$bytes=0;foreach($e['entries'] as $relative=>$entry){$p=$o['path'].($relative!==''?'/'.$relative:'');$s=lstat($p);$bytes+=$s['blocks']*512;}return ['identity'=>$o['identity'],'evidence'=>$e,'allocatedBytes'=>$bytes,'logicalBytes'=>$bytes];}
    $s=lstat($o['path']);$info=json_decode(unmLiveCommand(['qemu-img','info','--force-share','--output=json',$o['path']]),true,32,JSON_THROW_ON_ERROR);
    if(($info['format']??'')!==$o['disk']['format']||($info['virtual-size']??null)!==$o['disk']['bytes']||isset($info['backing-filename'])||!empty($info['snapshots'])||!empty($info['encrypted']))throw new RuntimeException('Retained image format/geometry changed.');
    return ['identity'=>$o['identity'],'stat'=>array_intersect_key($s,array_flip(['size','mtime','ctime'])),'allocatedBytes'=>$s['blocks']*512,'logicalBytes'=>$info['virtual-size']];
}
/** Expand only reviewed small-state entries; never recursively remove a path. */
function unmLiveCleanupItems(array $objects): array {
    $out=[];foreach($objects as $o){$e=$o['observed']['evidence']??null;if($e===null){$out[]=['id'=>$o['id'],'objectId'=>$o['id'],'path'=>$o['path'],'kind'=>$o['kind'],'identity'=>$o['identity'],'stat'=>$o['observed']['stat']??null];continue;}
        $entries=$e['entries'];uksort($entries,static fn($a,$b)=>strlen($b)<=>strlen($a)?:strcmp($a,$b));foreach($entries as $relative=>$entry)$out[]=['id'=>hash('sha256',$o['id'].'|'.$relative),'objectId'=>$o['id'],'path'=>$o['path'].($relative!==''?'/'.$relative:''),'kind'=>$entry['type'],'identity'=>['device'=>(string)$entry['device'],'inode'=>(string)$entry['inode']],'sha256'=>$entry['sha256']];
    }return $out;
}
