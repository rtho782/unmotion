<?php
// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
declare(strict_types=1);

/** Pure storage-contract validation, safe for the synchronous native hook. */
function unmLiveDiskIdentity(array $disk): array {
    $path=$disk['path']??null;$target=$disk['target']??null;$format=$disk['format']??null;
    if(!is_string($path)||!str_starts_with($path,'/mnt/')||preg_match('~\A/mnt/user0?(?:/|\z)|[\x00-\x1f\x7f]|(?:\A|/)\.{1,2}(?:/|\z)|//~D',$path)||!is_string($target)||!preg_match('/\A(?:vd|sd|hd)[a-z]\z/D',$target)||!in_array($format,['raw','qcow2'],true))throw new RuntimeException('Live migration requires independent raw/qcow2 files on direct pool paths.');
    return ['path'=>$path,'target'=>$target,'format'=>$format];
}
function unmLiveDisksXml(DOMXPath $xp): array {
    $out=[];$targets=[];$paths=[];
    foreach($xp->query('/domain/devices/disk') as $disk){
        $target=$xp->evaluate('string(target/@dev)',$disk);
        if($xp->query('./target',$disk)->length!==1||!preg_match('/\A(?:vd|sd|hd)[a-z]\z/D',$target)||isset($targets[$target]))throw new RuntimeException('Disk target identities are missing, duplicated or unsupported.');
        $targets[$target]=true;
        if($disk->getAttribute('device')==='cdrom'){
            if($disk->getAttribute('type')!=='file'||$xp->query('./source',$disk)->length>1||$xp->query('./source/@file[string(.)!=""]|./source/*|./backingStore/*|./mirror|./shareable',$disk)->length||$xp->query('./readonly',$disk)->length!==1)throw new RuntimeException('Detach attached ISO/CD-ROM media before live migration; empty read-only file CD-ROM drives are supported.');
            foreach($xp->query('./source/@*',$disk) as $a){if(!in_array($a->name,['file','index','startupPolicy'],true)||$a->name==='index'&&!preg_match('/\A[0-9]+\z/D',$a->value)||$a->name==='startupPolicy'&&!in_array($a->value,['mandatory','requisite','optional'],true))throw new RuntimeException('Unsupported empty CD-ROM source option.');}
            continue;
        }
        if($disk->getAttribute('type')!=='file'||$disk->getAttribute('device')!=='disk'||$xp->query('./source',$disk)->length!==1||$xp->query('./source/@file',$disk)->length!==1||$xp->query('./backingStore/*|./readonly|./shareable|./mirror|./source/*|./source/@dev|./source/@protocol',$disk)->length)throw new RuntimeException('Live migration excludes block/zvol, shared and dependent disks. Use Cold/Warm Move for supported alternatives.');
        $value=unmLiveDiskIdentity(['path'=>$xp->evaluate('string(source/@file)',$disk),'target'=>$target,'format'=>$xp->evaluate('string(driver/@type)',$disk)]);
        if(isset($paths[$value['path']]))throw new RuntimeException('Multiple writable disks reference the same image.');$paths[$value['path']]=true;
        if(!in_array($xp->evaluate('string(driver/@cache)',$disk),['none','directsync'],true))throw new RuntimeException('Use disk cache="none" or "directsync" and fully stop/start before live migration.');
        $out[]=$value;
    }
    if(count($out)<1||count($out)>16)throw new RuntimeException('Live migration supports one to sixteen independent file disks.');
    return $out;
}
/** Keep existing one-disk job records readable without changing their authority. */
function unmLiveDisks(array $spec): array {
    $disks=$spec['disks']??[['path'=>$spec['diskPath']??null,'target'=>$spec['diskTarget']??null,'format'=>$spec['format']??null,'bytes'=>$spec['diskBytes']??null]];
    if(!is_array($disks)||!array_is_list($disks)||count($disks)<1||count($disks)>16)throw new RuntimeException('Invalid live storage contract.');
    $out=[];$targets=[];$paths=[];
    foreach($disks as $disk){if(!is_array($disk))throw new RuntimeException('Invalid live disk record.');$identity=unmLiveDiskIdentity($disk);if(isset($targets[$identity['target']])||isset($paths[$identity['path']]))throw new RuntimeException('Duplicate live storage contract.');$targets[$identity['target']]=true;$paths[$identity['path']]=true;if(isset($disk['bytes'])){if(!is_int($disk['bytes'])||$disk['bytes']<1)throw new RuntimeException('Invalid live disk capacity.');$identity['bytes']=$disk['bytes'];}$out[]=$identity;}
    if(isset($spec['diskPath'])&&$spec['diskPath']!==$out[0]['path']||isset($spec['diskTarget'])&&$spec['diskTarget']!==$out[0]['target']||isset($spec['format'])&&$spec['format']!==$out[0]['format'])throw new RuntimeException('Legacy and multi-disk identities disagree.');
    return $out;
}
function unmLiveDiskTotal(array $disks): int {
    $total=0;foreach($disks as $disk){$n=$disk['bytes']??null;if(!is_int($n)||$n<1||$n>PHP_INT_MAX-$total)throw new RuntimeException('Missing or overflowing live disk capacity.');$total+=$n;}return $total;
}
