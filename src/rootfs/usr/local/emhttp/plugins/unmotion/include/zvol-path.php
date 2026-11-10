<?php
// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
declare(strict_types=1);

/** OpenZFS zvol_id replaces blanks with '+'. This is not URL encoding. */
function unmZvolNameSafe(string $name): bool {
    if($name===''||strlen($name)>1024||$name!==trim($name)||str_starts_with($name,'/')||str_ends_with($name,'/')||preg_match('/[@\x00-\x1f\x7f]/',$name))return false;
    $parts=explode('/',$name);if(count($parts)<2)return false;
    foreach($parts as $part)if(in_array($part,['','.','..'],true))return false;return true;
}
function unmZvolDevicePath(string $dataset): string {
    if(!unmZvolNameSafe($dataset))throw new InvalidArgumentException('Unsafe ZFS volume name.');
    return '/dev/zvol/'.str_replace([" ","\t"],'+',$dataset);
}
function unmZvolPathNeedsIdentity(string $path): bool {return str_starts_with($path,'/dev/zvol/')&&strpbrk($path," +\t")!==false;}
function unmZvolPathAliases(string $dataset): array {return array_values(array_unique(['/dev/zvol/'.$dataset,unmZvolDevicePath($dataset)]));}
function unmZvolInventoryMatch(string $path,string $kernelName,array $volumes): string {
    if(!str_starts_with($path,'/dev/zvol/')||!unmZvolNameSafe($kernelName)||unmZvolDevicePath(substr($path,10))!==unmZvolDevicePath($kernelName))throw new RuntimeException('Zvol device does not match its kernel identity.');
    $matches=[];
    foreach($volumes as $name)if(is_string($name)&&unmZvolNameSafe($name)&&unmZvolDevicePath($name)==='/dev/zvol/'.$kernelName)$matches[$name]=true;
    if(count($matches)!==1)throw new RuntimeException('Zvol alias is missing or ambiguous (literal plus versus space); no storage was changed.');
    return (string)array_key_first($matches);
}

/** Fresh, fail-closed kernel + volume inventory proof for encoded aliases. */
function unmZvolSourceDataset(string $path): string {
    if(!str_starts_with($path,'/dev/zvol/'))throw new RuntimeException('Expected a ZFS volume device path.');
    $name=substr($path,10);if(!unmZvolNameSafe($name))throw new RuntimeException('Unsafe Zvol device path.');
    if(!unmZvolPathNeedsIdentity($path))return $name;
    clearstatcache(true,$path);$device=realpath($path);$before=@stat($path);
    if(!is_string($device)||!preg_match('~\A/dev/zd[0-9]+\z~D',$device)||!is_array($before)||($before['mode']&0170000)!==0060000)throw new RuntimeException('Encoded zvol path is not a whole, provable ZFS block device.');
    $helper=null;
    foreach(['/lib/udev/zvol_id','/usr/lib/udev/zvol_id'] as $candidate){$real=realpath($candidate);$st=$real!==false?@stat($real):false;if($real!==false&&is_array($st)&&$st['uid']===0&&($st['mode']&0022)===0&&is_executable($real)){$helper=$real;break;}}
    if($helper===null)throw new RuntimeException('OpenZFS zvol_id is required to resolve encoded volume paths.');
    $id=unmRun([$helper,$device],null,10);if($id['code']!==0)throw new RuntimeException('Unable to verify the kernel zvol identity.');$kernel=trim($id['stdout']);
    $pool=explode('/',$name)[0];if(!preg_match('/\A[A-Za-z][A-Za-z0-9_.:-]*\z/D',$pool))throw new RuntimeException('Unsafe ZFS pool identity.');
    $inventory=unmRun(['zfs','list','-H','-t','volume','-o','name','-r',$pool],null,20);if($inventory['code']!==0)throw new RuntimeException('Unable to prove volume inventory for an encoded zvol path.');
    $dataset=unmZvolInventoryMatch($path,$kernel,array_filter(explode("\n",rtrim($inventory['stdout'],"\r\n"))));
    clearstatcache(true,$path);$after=@stat($path);
    if(realpath($path)!==$device||!is_array($after)||$before['rdev']!==$after['rdev']||$before['ino']!==$after['ino'])throw new RuntimeException('Zvol device identity changed during inspection.');
    return $dataset;
}
/** Exact alias lookup within a previously validated VM mapping, never a global replacement. */
function unmZvolMappingKey(string $path,array $mappings): string {
    $matches=[];
    foreach(array_keys($mappings) as $key)if(is_string($key)&&str_starts_with($key,'/dev/zvol/')&&in_array($path,unmZvolPathAliases(substr($key,10)),true))$matches[]=$key;
    if(count($matches)!==1)throw new RuntimeException('Zvol XML path has no unique storage mapping.');
    return $matches[0];
}
function unmZvolAssertPeer(array $vm,array $caps): void {
    foreach((array)($vm['disks']??[]) as $disk)if(($disk['type']??'')==='zvol'&&unmZvolPathNeedsIdentity((string)($disk['source']??''))&&($caps['zvolPathVersion']??0)!==1)throw new RuntimeException('Encoded Zvol paths require Beta7 or later support on both peers. Upgrade the destination before transferring this VM.');
}
