<?php
// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
declare(strict_types=1);
require_once __DIR__.'/live-zvol-native.php';

/** Always resolve through the kernel, including names without encoded blanks. */
function unmLiveZvolInspect(string $path): array {
    unmLiveDiskIdentity(['path'=>$path,'target'=>'vda','format'=>'raw','type'=>'zvol']);$before=unmLiveZvolKernel($path);$helper=null;
    foreach(['/lib/udev/zvol_id','/usr/lib/udev/zvol_id'] as $p){$real=realpath($p);$st=$real?@stat($real):false;if($st&&$st['uid']===0&&($st['mode']&0022)===0&&is_executable($real)){$helper=$real;break;}}
    if($helper===null)throw new RuntimeException('A trusted OpenZFS zvol_id is required.');
    $kernel=trim(unmLiveCommand([$helper,$before['node']]));$pool=explode('/',substr($path,10))[0];
    $inventory=explode("\n",trim(unmLiveCommand(['zfs','list','-H','-t','volume','-o','name','-r',$pool])));
    $name=unmZvolInventoryMatch($path,$kernel,$inventory);
    $props=[];foreach(explode("\n",trim(unmLiveCommand(['zfs','get','-Hp','-o','property,value','type,guid,volsize,volblocksize,readonly,volmode,encryption',$name]))) as $line){$kv=explode("\t",$line,2);if(count($kv)===2)$props[$kv[0]]=$kv[1];}
    if(($props['type']??'')!=='volume'||($props['readonly']??'')!=='off'||!in_array($props['volmode']??'',['default','full','dev'],true)||($props['encryption']??'')!=='off')throw new RuntimeException('Live zvols must be writable, device-visible and without native ZFS encryption. Guest BitLocker is supported separately.');
    foreach(['volsize','volblocksize'] as $k)if(!ctype_digit($props[$k]??'')||(float)$props[$k]>PHP_INT_MAX)throw new RuntimeException('Invalid ZFS volume size.');
    $v=unmLiveVolumeContract(['dataset'=>$name,'guid'=>$props['guid']??'','volsize'=>(int)$props['volsize'],'volblocksize'=>(int)$props['volblocksize']]);
    if($before!==unmLiveZvolKernel($path)||$before['bytes']!==$v['volsize'])throw new RuntimeException('ZFS/kernel volume identity or size changed.');
    return $v;
}
function unmLiveZvolRefresh(array $r): void {
    if(($r['workflow']??'')==='ordinary'||!isset($r['disks']))return;$devices=[];
    foreach(unmLiveDisks($r) as $disk){if(($disk['type']??'')!=='zvol')continue;$k=unmLiveZvolKernel($disk['path']);if(unmLiveZvolInspect($disk['path'])!==($disk['volume']??null)||$k!==unmLiveZvolKernel($disk['path']))throw new RuntimeException('The original ZFS volume was replaced or resized.');$devices[$disk['target']]=$k;}
    if(!$devices)return;
    $lock=unmNativeLock('live-'.$r['vmUuid']);try{$current=unmLiveNativeRecord(UNM_BOOT_DIR,$r['vmUuid']);if(!$current||unmLiveZvolProofKey($current)!==unmLiveZvolProofKey($r))throw new RuntimeException('Live storage authority changed during verification.');unmNativeWrite('/run/unmotion/live-zvol/'.$r['vmUuid'].'.json',['bootId'=>unmNativeBoot(),'binding'=>unmLiveZvolProofKey($r),'observedAt'=>gmdate('c'),'devices'=>$devices]);}finally{flock($lock,LOCK_UN);fclose($lock);}
}
function unmLiveZvolRefreshAll(): void {
    foreach(glob(UNM_BOOT_DIR.'/live-managed/*.json')?:[] as $p){$uuid=basename($p,'.json');try{$r=unmLiveNativeRecord(UNM_BOOT_DIR,unmTransportUuid($uuid));if(!$r||!isset($r['disks'])||($r['workflow']??'')==='ordinary')continue;$has=false;foreach(unmLiveDisks($r) as $disk)if(($disk['type']??'')==='zvol')$has=true;if(!$has)continue;
        // A current kernel-lifetime proof needs no periodic CLI query. On boot,
        // import/replacement or a new authority, refresh asynchronously instead.
        try{unmLiveZvolNativeCheck($r,unmNativeBoot(),'/run/unmotion/live-zvol/'.$uuid.'.json');}catch(Throwable){unmLiveZvolRefresh($r);}
    }catch(Throwable $e){$path='/run/unmotion/live-zvol-errors/'.basename($uuid).'.json';$last=is_file($path)?unmLoadJson($path):[];if(($last['error']??'')!==$e->getMessage()||time()-(int)($last['loggedAt']??0)>=300){error_log('unMotion local zvol verification '.$uuid.': '.$e->getMessage());try{unmNativeWrite($path,['loggedAt'=>time(),'error'=>$e->getMessage()]);}catch(Throwable){}}}}
}
/** Pool-aware conservative budget; file and zvol requirements sharing a pool add up. */
function unmLiveCapacityBudget(array $items): array {
    $groups=[];foreach($items as $item){if(!is_int($item['bytes']??null)||$item['bytes']<1||!is_int($item['available']??null)||$item['available']<0)throw new RuntimeException('Capacity evidence is unavailable.');$key=$item['group'];$g=$groups[$key]??['group'=>$key,'requiredBytes'=>0,'availableBytes'=>$item['available']];$extra=max(67108864,(int)ceil($item['bytes']*(($item['kind']??'')==='zvol'?0.03:0.01)));if($item['bytes']>PHP_INT_MAX-$extra-$g['requiredBytes'])throw new RuntimeException('Capacity estimate overflow.');$g['requiredBytes']+=$item['bytes']+$extra;$g['availableBytes']=min($g['availableBytes'],$item['available']);$groups[$key]=$g;}
    foreach($groups as &$g){$g['headroomBytes']=(int)ceil($g['availableBytes']*0.2);$g['sufficient']=$g['requiredBytes']<=$g['availableBytes']-$g['headroomBytes'];}unset($g);return array_values($groups);
}
function unmLiveStoragePlan(array $sourceDisks,?string $id=null,?array $config=null): array {
    $disks=unmLiveDisks(['disks'=>$sourceDisks]);unmLiveDiskTotal($disks);$cfg=$config??unmLoadConfig();$items=[];$dest=[];$root=null;$parent=null;
    if(!in_array($cfg['dedup']??null,['off','on','verify'],true))throw new RuntimeException('Invalid destination dedup policy.');
    foreach($disks as $i=>$d){$z=($d['type']??'')==='zvol';
        if($z){unmLiveVolumeContract($d['volume']??null);$parent=(string)$cfg['zvol_dataset'];if(!preg_match('~\A[A-Za-z][A-Za-z0-9_.:-]*(?:/[A-Za-z0-9_.-]+)+\z~D',$parent)||preg_match('~(?:\A|/)\.{1,2}(?:/|\z)~D',$parent))throw new RuntimeException('Configure an existing destination zvol parent dataset.');
            $props=[];foreach(explode("\n",trim(unmLiveCommand(['zfs','get','-Hp','-o','property,value','type,available,readonly,encryption',$parent]))) as $line){$kv=explode("\t",$line,2);if(count($kv)===2)$props[$kv[0]]=$kv[1];}if(($props['type']??'')!=='filesystem'||($props['readonly']??'')!=='off'||($props['encryption']??'')!=='off'||!ctype_digit($props['available']??''))throw new RuntimeException('Destination zvol parent must be writable and unencrypted.');
            $free=(int)$props['available'];$group='zfs:'.explode('/',$parent)[0];$dataset=$parent.'/unmotion-'.($id??'live-preview').'-vdisk'.($i+1);$path=unmZvolDevicePath($dataset);$entry=['path'=>$path,'target'=>$d['target'],'format'=>'raw','type'=>'zvol','bytes'=>$d['bytes'],'plannedDataset'=>$dataset,'volblocksize'=>$d['volume']['volblocksize']];
        }else{
            $root=rtrim((string)$cfg['image_dir'],'/');unmTransportPath($root);if(!str_starts_with($root,'/mnt/')||preg_match('~\A/mnt/user0?/~D',$root)||realpath($root)!==$root||!is_dir($root)||!is_writable($root))throw new RuntimeException('Configure a direct writable pool image directory.');unmLiveFileSystem($root);$free=disk_free_space($root);if($free===false)throw new RuntimeException('Destination free capacity unavailable.');$free=(int)$free;$fs=trim(unmLiveCommand(['findmnt','-n','-o','FSTYPE','--target',$root]));$group='fs:'.stat($root)['dev'];if($fs==='zfs'){$dataset=trim(unmLiveCommand(['findmnt','-n','-o','SOURCE','--target',$root]));$group='zfs:'.explode('/',$dataset)[0];}
            $entry=['path'=>$root.'/unmotion-'.($id??'live-preview').'/vdisk'.($i+1).'.'.$d['format'],'target'=>$d['target'],'format'=>$d['format'],'bytes'=>$d['bytes']];
        }
        if($z){$estimate=unmLiveCommand(['zfs','create','-nPv','-V',(string)$d['bytes'],'-b',(string)$entry['volblocksize'],'-o','dedup='.$cfg['dedup'],'-o','volmode=dev',$entry['plannedDataset']]);$entry['reservationBytes']=unmLiveReservationEstimate($estimate,$d['bytes']);}
        $items[]=['group'=>$group,'kind'=>$z?'zvol':'file','bytes'=>$z?$entry['reservationBytes']:$d['bytes'],'available'=>$free];$dest[]=$entry;
    }
    $groups=unmLiveCapacityBudget($items);foreach($groups as $g)if(!$g['sufficient'])throw new RuntimeException('Insufficient destination capacity in '.$g['group'].': full virtual disks plus allocation overhead and 20% free-space headroom are required.');
    if(!in_array($cfg['dedup'],['off','on','verify'],true))throw new RuntimeException('Invalid destination dedup policy.');
    return ['disks'=>$dest,'groups'=>$groups,'imageDirectory'=>$root,'zvolParent'=>$parent,'zvolPolicy'=>'Full reservation; no thin overcommit','dedup'=>$cfg['dedup'],'retention'=>'Source and partial copies are retained; their space is not reclaimed automatically.'];
}
function unmLiveStorageProvision(array $plan,?callable $created=null): array {
    $out=[];foreach($plan['disks'] as $d){if(($d['type']??'')==='zvol'){
        $dataset=$d['plannedDataset'];if(unmRun(['zfs','list','-H','-o','name',$dataset],null,10)['code']===0||file_exists($d['path'])||is_link($d['path']))throw new RuntimeException('Destination zvol already exists.');
        // Non-sparse -V computes a full reservation. Explicit '=auto' during
        // create is broken on a qualified lab build; never retry as sparse.
        unmLiveCommand(['zfs','create','-V',(string)$d['bytes'],'-b',(string)$d['volblocksize'],'-o','dedup='.$plan['dedup'],'-o','volmode=dev',$dataset],null,30);
        $until=microtime(true)+5;do{clearstatcache(true,$d['path']);if(file_exists($d['path']))break;usleep(100000);}while(microtime(true)<$until);
        $d['volume']=unmLiveZvolInspect($d['path']);if($d['volume']['dataset']!==$dataset||$d['volume']['volsize']!==$d['bytes'])throw new RuntimeException('Provisioned volume geometry changed.');unset($d['plannedDataset'],$d['volblocksize']);
        $reservation=trim(unmLiveCommand(['zfs','get','-Hp','-o','value','refreservation',$dataset]));if(!ctype_digit($reservation)||(float)$reservation<$d['reservationBytes'])throw new RuntimeException('Destination zvol is not fully reserved.');unset($d['reservationBytes']);
    }else{unmLivePrivateDir(dirname($d['path']));if(file_exists($d['path'])||is_link($d['path']))throw new RuntimeException('Destination image already exists.');unmLiveCommand(['qemu-img','create','-f',$d['format'],$d['path'],(string)$d['bytes']]);}if($created!==null)$created($d);$out[]=$d;}return $out;
}
function unmLiveReservationEstimate(string $dryRun,int $minimum): int {
    $values=[];foreach(explode("\n",trim($dryRun)) as $line){$p=explode("\t",$line);if(($p[0]??'')==='property'&&($p[1]??'')==='refreservation')$values[]=$p[2]??'';}
    if(count($values)!==1||!ctype_digit($values[0])||strlen($values[0])>strlen((string)PHP_INT_MAX)||strlen($values[0])===strlen((string)PHP_INT_MAX)&&strcmp($values[0],(string)PHP_INT_MAX)>0||(int)$values[0]<$minimum)throw new RuntimeException('ZFS did not prove a full destination reservation in dry-run output.');return (int)$values[0];
}
