<?php
declare(strict_types=1);
namespace MigrationStorageFixture;
use RuntimeException;
use InvalidArgumentException;
use Throwable;
use DOMDocument;
use DOMXPath;

$include=__DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/';$lib=file_get_contents($include.'lib.php');
function loadFunctions(string $source,string $first,?string $next=null): void {
    $start=strpos($source,'function '.$first.'(');$end=$next===null?strlen($source):strpos($source,'function '.$next.'(',$start);
    if($start===false||$end===false)throw new RuntimeException('Fixture function boundaries changed.');
    eval('namespace MigrationStorageFixture; use RuntimeException; use InvalidArgumentException; use Throwable; use DOMDocument; use DOMXPath;'.substr($source,$start,$end-$start));
}
loadFunctions($lib,'unmClassifyVmStorage','unmGuestAgentStatus');
loadFunctions($lib,'unmCloneDatasetIsolated','unmClonePreflight');
loadFunctions($lib,'unmReplicationDatasetIsolationErrors','unmReplicationZvolUsedByOtherVm');
loadFunctions(file_get_contents($include.'migration-storage.php'),'unmMigrationAssertSourceDataset');
$mount='/mnt/pool/Squid Proxy';$dataset='pool/domains/Squid Proxy';$disk=$mount.'/disk.img';$uuid='f0470000-aaaa-4aaa-8aaa-aaaaaaaaaaaa';$job='20261013-test';$commands=[];
function resetFixture(): void {
    global $mount,$dataset,$disk,$job,$files,$children,$snapshots,$holds,$commands,$failure,$nested,$otherVm,$readonly,$destroyFail,$raceChild,$links,$hardlinks,$nvram,$busyRemaining,$mounted;
    $files=[$disk=>'f'];$children=[$dataset];$snapshots=[$dataset.'@unmotion-'.$job];$holds=[];$commands=[];$failure=false;$nested=false;$otherVm=false;$readonly="off\tlocal";$destroyFail=false;$raceChild=false;$links=[];$hardlinks=false;$nvram='';$busyRemaining=0;$mounted=true;
}
function check(bool $ok,string $why): void {if(!$ok)throw new RuntimeException($why);}
function reject(callable $fn,string $why): void {try{$fn();}catch(Throwable $e){return;}throw new RuntimeException('Accepted: '.$why);}
function realpath(string $path): string|false {return isset($GLOBALS['links'][$path])?false:$path;}
function is_file(string $path): bool {return ($GLOBALS['files'][$path]??'')==='f';}
function is_dir(string $path): bool {return $path===$GLOBALS['mount'];}
function is_link(string $path): bool {return isset($GLOBALS['links'][$path]);}
function stat(string $path): array {return ['nlink'=>$GLOBALS['hardlinks']?2:1];}
function usleep(int $microseconds): void {}
function unmHostStateNoNestedMounts(string $path): void {if($GLOBALS['nested'])throw new RuntimeException('Nested mount');}
function unmDatasetUsedByOtherVm(string $mount,string $uuid,?array $index=null): bool {return $GLOBALS['otherVm'];}
function unmResolveUserSharePath(string $path): array {return ['physicalPath'=>str_replace('/mnt/user/Squid Proxy',$GLOBALS['mount'],$path)];}
function unmZfsPathInfo(string $path): array {return ['dataset'=>$GLOBALS['dataset'],'mountpoint'=>$GLOBALS['mount'],'encryption'=>'off'];}
function unmImageBackingInfo(string $path,string $format): array {return ['hasBackingChain'=>false];}
function unmCommonDirectory(array $paths): string {return $GLOBALS['mount'];}
function unmOwnedNvram(array $vm): array {return $GLOBALS['nvram']===''?['kind'=>'none']:['kind'=>'custom','source'=>$GLOBALS['nvram'],'diskSource'=>$GLOBALS['disk']];}
function unmPathWithin(string $path,string $root): bool {return str_starts_with($path,$root.'/');}
function unmZfsObjectNameSafe(string $path): bool {return $path===$GLOBALS['dataset'];}
function unmRun(array $args,?string $stdin=null,int $timeout=60): array {
    global $dataset,$mount,$children,$snapshots,$holds,$files,$commands,$failure,$readonly,$destroyFail,$raceChild,$busyRemaining,$mounted;
    $commands[]=$args;$out='';$code=0;
    if($args[0]==='find'){foreach($files as $path=>$kind)$out.=$kind."\0".$path."\0";}
    elseif($args[0]==='zfs'){
        if($args[1]==='list'){
            if(in_array('snapshot',$args,true))$out=implode("\n",$snapshots);
            elseif(in_array('-r',$args,true))$out=implode("\n",$children);
            else $out=$dataset;
        }elseif($args[1]==='holds')$out=implode("\n",$holds);
        elseif($args[1]==='get')$out=in_array('mountpoint',$args,true)?$mount:(in_array('mounted',$args,true)?($mounted?'yes':'no'):$readonly);
        elseif($args[1]==='mount')$mounted=true;
        elseif($args[1]==='set'||$args[1]==='inherit'){}
        elseif($args[1]==='destroy'){
            check(count($args)===3&&!in_array('-r',$args,true),'Every destruction names one exact object');
            if($args[2]===$dataset){if($busyRemaining>0){$busyRemaining--;$mounted=false;$code=1;}elseif($destroyFail||count($children)>1||$snapshots){$mounted=false;$code=1;}else $children=[];}
            else{$snapshots=array_values(array_diff($snapshots,[$args[2]]));if($raceChild)$children[]=$dataset.'/unrelated';}
        }else throw new RuntimeException('Unexpected ZFS command');
    }else throw new RuntimeException('Unexpected command');
    return ['code'=>$failure?1:$code,'stdout'=>$out,'stderr'=>'fixture failure'];
}
function classify(): array {global $uuid,$disk;return unmClassifyVmStorage(['uuid'=>$uuid,'name'=>'Squid Proxy','disks'=>[['type'=>'file','source'=>$disk,'format'=>'raw']]],true)['disks'][0];}
function xml(): string {global $uuid,$disk,$nvram;return '<domain><uuid>'.$uuid.'</uuid><os>'.($nvram!==''?'<nvram>'.$nvram.'</nvram>':'').'</os><devices><disk type="file" device="disk"><source file="'.$disk.'"/></disk></devices></domain>';}
resetFixture();check(classify()['transferClass']==='zfs-dataset-image','Isolated VM keeps native transfer');
check(in_array(['zfs','list','-H','-r','-d','1','-t','filesystem,volume','-o','name',$dataset],$commands,true),'Snapshot visibility settings cannot turn snapshots into child datasets');
foreach(['child','unrelated-file','unrelated-directory','link','hardlink','nested-mount','inventory-failure','other-vm'] as $scenario){
    resetFixture();
    switch($scenario){
        case 'child':$children[]=$dataset.'/Unrelated Data';break;
        case 'unrelated-file':$files[$mount.'/private.txt']='f';break;
        case 'unrelated-directory':$files[$mount.'/extra']='d';break;
        case 'link':$links[$disk]=true;break;
        case 'hardlink':$hardlinks=true;break;
        case 'nested-mount':$nested=true;break;
        case 'inventory-failure':$failure=true;break;
        case 'other-vm':$otherVm=true;break;
    }
    check(classify()['transferClass']==='shared-zfs-image',$scenario.' selects exact-file rsync');
    reject(fn()=>unmMigrationAssertSourceDataset($dataset,xml(),$uuid),$scenario.' cannot delete source dataset');
    check(!array_filter($commands,fn($a)=>$a[1]==='destroy'),'Isolation refusal makes no destructive calls');
}
resetFixture();$nvram=$mount.'/VARS.fd';$files[$nvram]='f';check(classify()['transferClass']==='zfs-dataset-image','Proven custom NVRAM remains native eligible');unmMigrationAssertSourceDataset($dataset,xml(),$uuid);
foreach(['foreign-snapshot','held-snapshot','child','failed-inventory'] as $scenario){
    resetFixture();if($scenario==='foreign-snapshot')$snapshots[]=$dataset.'@backup';if($scenario==='held-snapshot')$holds[]='hold';if($scenario==='child')$children[]=$dataset.'/unrelated';if($scenario==='failed-inventory')$failure=true;
    reject(fn()=>unmDestroyMigrationSourceObject($dataset,$job),$scenario.' cleanup');check(!array_filter($commands,fn($a)=>$a[1]==='destroy'),'Snapshot/child refusal precedes every deletion');
}
resetFixture();unmDestroyMigrationSourceObject($dataset,$job);$destroy=array_values(array_filter($commands,fn($a)=>$a[1]==='destroy'));check($destroy===[['zfs','destroy',$dataset.'@unmotion-'.$job],['zfs','destroy',$dataset]],'Only this job snapshot and exact source object removed');
resetFixture();$raceChild=true;reject(fn()=>unmDestroyMigrationSourceObject($dataset,$job),'child created after checks');check(in_array($dataset.'/unrelated',$children,true),'Nonrecursive destroy preserves raced child');
resetFixture();$busyRemaining=2;unmDestroyMigrationSourceObject($dataset,$job);check(!$children,'Transient busy failures retry the exact dataset successfully');
resetFixture();$destroyFail=true;$prior=unmMigrationFreezeSourceDataset($dataset);reject(fn()=>unmDestroyMigrationSourceObject($dataset,$job),'persistent busy failure');check(!$mounted&&count($children)===1,'Persistent busy failure retains the dataset');unmMigrationRestoreSourceDataset($dataset,$prior);check($mounted,'Failed cleanup restores the original mounted state');
foreach(['local','received','inherited from pool','default'] as $source){
    resetFixture();$readonly="off\t".$source;$prior=unmMigrationFreezeSourceDataset($dataset);unmMigrationRestoreSourceDataset($dataset,$prior);
    $want=$source==='local'?['zfs','set','readonly=off',$dataset]:($source==='received'?['zfs','inherit','-S','readonly',$dataset]:['zfs','inherit','readonly',$dataset]);check(in_array($want,$commands,true),'Failed cleanup restores original readonly source '.$source);
}
resetFixture();$readonly="on\tlocal";$prior=unmMigrationFreezeSourceDataset($dataset);unmMigrationRestoreSourceDataset($dataset,$prior);check(!array_filter($commands,fn($a)=>$a[1]==='set'||$a[1]==='inherit'),'Already read-only property is preserved');
$cleanup=substr($lib,strpos($lib,'function unmFinalizeSourceCleanupLocked('),strpos($lib,'function unmRemoteFsCapacity(')-strpos($lib,'function unmFinalizeSourceCleanupLocked('));
check(strpos($cleanup,'unmResolutionDeleteManifest(')<strpos($cleanup,"['virsh','undefine'"),'Normal cleanup validates original disk ownership before undefining');
check(strpos($cleanup,'unmMigrationSourceSnapshots(')<strpos($cleanup,"['virsh','undefine'"),'Foreign snapshots/holds block before undefining');
check(!str_contains($cleanup,"'destroy','-r'"),'Source cleanup never recursively destroys ZFS');
echo "Migration isolation, shared-file fallback, custom NVRAM, exact snapshots, holds, child races and readonly restoration passed.\n";
