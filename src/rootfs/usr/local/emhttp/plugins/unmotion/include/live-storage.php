<?php
// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
declare(strict_types=1);

/** Pure storage-contract validation, safe for the synchronous native hook. */
function unmLiveDiskIdentity(array $disk): array {
    $path=$disk['path']??null;$target=$disk['target']??null;$format=$disk['format']??null;
    $zvol=($disk['type']??'file')==='zvol';
    if(!is_string($path)||strlen($path)>2048||preg_match('~[\x00-\x1f\x7f]|(?:\A|/)\.{1,2}(?:/|\z)|//~D',$path)||!is_string($target)||!preg_match('/\A(?:vd|sd|hd)[a-z]\z/D',$target)||!in_array($format,['raw','qcow2'],true)||!in_array($disk['type']??'file',['file','zvol'],true))throw new RuntimeException('Invalid live disk identity.');
    if($zvol){if($format!=='raw'||!preg_match('~\A/dev/zvol/[A-Za-z][A-Za-z0-9_.:-]*/[^@#]+\z~D',$path)||str_ends_with($path,'/'))throw new RuntimeException('Live block disks must be whole raw ZFS volumes.');}
    elseif(!str_starts_with($path,'/mnt/')||preg_match('~\A/mnt/user0?(?:/|\z)~D',$path))throw new RuntimeException('Live file disks require direct pool paths.');
    return ['path'=>$path,'target'=>$target,'format'=>$format]+($zvol?['type'=>'zvol']:[]);
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
        $block=$disk->getAttribute('type')==='block';$attr=$block?'dev':'file';
        if(!in_array($disk->getAttribute('type'),['file','block'],true)||$disk->getAttribute('device')!=='disk'||$xp->query('./source',$disk)->length!==1||$xp->query('./source/@'.$attr,$disk)->length!==1||$xp->query('./backingStore/*|./readonly|./shareable|./mirror|./source/*',$disk)->length)throw new RuntimeException('Live migration excludes shared and dependent disks.');
        foreach($xp->query('./source/@*',$disk) as $a)if(!in_array($a->name,[$attr,'index','startupPolicy'],true)||$a->name==='index'&&!preg_match('/\A[0-9]+\z/D',$a->value)||$a->name==='startupPolicy'&&!in_array($a->value,['mandatory','requisite','optional'],true))throw new RuntimeException('Unsupported live disk source attributes.');
        $value=unmLiveDiskIdentity(['path'=>$xp->evaluate('string(source/@'.$attr.')',$disk),'target'=>$target,'format'=>$xp->evaluate('string(driver/@type)',$disk),'type'=>$block?'zvol':'file']);
        if(isset($paths[$value['path']]))throw new RuntimeException('Multiple writable disks reference the same image.');$paths[$value['path']]=true;
        if(!in_array($xp->evaluate('string(driver/@cache)',$disk),['none','directsync'],true))throw new RuntimeException('Use disk cache="none" or "directsync" and fully stop/start before live migration.');
        $out[]=$value;
    }
    if(count($out)<1||count($out)>16)throw new RuntimeException('Live migration supports one to sixteen independent file/zvol disks.');
    return $out;
}
/** Keep existing one-disk job records readable without changing their authority. */
function unmLiveDisks(array $spec): array {
    $disks=$spec['disks']??[['path'=>$spec['diskPath']??null,'target'=>$spec['diskTarget']??null,'format'=>$spec['format']??null,'bytes'=>$spec['diskBytes']??null]];
    if(!is_array($disks)||!array_is_list($disks)||count($disks)<1||count($disks)>16)throw new RuntimeException('Invalid live storage contract.');
    $out=[];$targets=[];$paths=[];
    foreach($disks as $disk){if(!is_array($disk))throw new RuntimeException('Invalid live disk record.');$identity=unmLiveDiskIdentity($disk);if(isset($targets[$identity['target']])||isset($paths[$identity['path']]))throw new RuntimeException('Duplicate live storage contract.');$targets[$identity['target']]=true;$paths[$identity['path']]=true;if(isset($disk['bytes'])){if(!is_int($disk['bytes'])||$disk['bytes']<1)throw new RuntimeException('Invalid live disk capacity.');$identity['bytes']=$disk['bytes'];}if(isset($disk['volume'])){$identity['volume']=unmLiveVolumeContract($disk['volume']);if(($identity['type']??'')!=='zvol'||isset($identity['bytes'])&&$identity['bytes']!==$identity['volume']['volsize'])throw new RuntimeException('Zvol geometry disagrees with disk capacity.');}$out[]=$identity;}
    if(isset($spec['diskPath'])&&$spec['diskPath']!==$out[0]['path']||isset($spec['diskTarget'])&&$spec['diskTarget']!==$out[0]['target']||isset($spec['format'])&&$spec['format']!==$out[0]['format'])throw new RuntimeException('Legacy and multi-disk identities disagree.');
    return $out;
}
function unmLiveVolumeContract(mixed $v): array {
    if(!is_array($v)||array_keys($v)!==['dataset','guid','volsize','volblocksize']||!is_string($v['dataset'])||!preg_match('~\A[A-Za-z][A-Za-z0-9_.:-]*/[^@#\x00-\x1f\x7f]+\z~D',$v['dataset'])||preg_match('~(?:\A|/)\.{0,2}(?:/|\z)~D',$v['dataset'])||!is_string($v['guid'])||!preg_match('/\A[1-9][0-9]{0,19}\z/D',$v['guid'])||!is_int($v['volsize'])||$v['volsize']<1||!is_int($v['volblocksize'])||$v['volblocksize']<512||$v['volblocksize']>131072||($v['volblocksize']&($v['volblocksize']-1))!==0||$v['volsize']%$v['volblocksize']!==0)throw new RuntimeException('Invalid ZFS volume identity/geometry.');
    return $v;
}
function unmLiveDiskTotal(array $disks): int {
    $total=0;foreach($disks as $disk){$n=$disk['bytes']??null;if(!is_int($n)||$n<1||$n>PHP_INT_MAX-$total)throw new RuntimeException('Missing or overflowing live disk capacity.');$total+=$n;}return $total;
}
function unmLiveProvisioningAbortable(?array $r,array $intent): bool {
    if($r===($intent['priorAuthority']??null))return $r===null||in_array($r['state']??'',['RETIRED','ABORTED'],true);
    if($r===null||($r['state']??'')!=='ABORTED'||($r['role']??'')!=='destination'||($r['jobId']??'')!==($intent['id']??''))return false;
    foreach(['vmUuid','sourceHostId','destinationHostId','diskPath','bootId'] as $key)if(!isset($r[$key],$intent[$key])||$r[$key]!==$intent[$key])return false;
    return true;
}
