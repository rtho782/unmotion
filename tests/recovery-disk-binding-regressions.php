<?php
// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
declare(strict_types=1);
require_once __DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/lib.php';
$source='/mnt/source/Squid Proxy/vdisk.img';$target='/mnt/destination/activation/disk1/vdisk.img';$maps=[$source=>$target];
$disk=static fn(string $path,string $device='disk'):string=>'<disk type="file" device="'.$device.'"><driver name="qemu" type="raw"/><source file="'.$path.'"/><target dev="vda"/></disk>';
$xml=static fn(string $devices):string=>'<domain><name>Squid Proxy</name><devices>'.$devices.'</devices></domain>';
$reject=static function(callable $fn,string $label):void {try{$fn();}catch(RuntimeException $e){return;}throw new RuntimeException('Unsafe recovery disk mapping accepted: '.$label);};
unmRecoveryAssertDiskMappings($xml($disk($target)),$maps);
unmRecoveryAssertDiskMappings($xml(str_replace('device="disk"','device = "disk"',$disk($target))),$maps);
unmRecoveryAssertDiskMappings($xml($disk($target).'<disk type="file" device="cdrom"><target dev="sda"/></disk>'),$maps);
unmRecoveryTransformDomainXml($xml($disk($source)),$maps);
$reject(fn()=>unmRecoveryAssertDiskMappings($xml($disk($source)),$maps),'original source');
$reject(fn()=>unmRecoveryAssertDiskMappings($xml($disk($target).$disk('/mnt/another-vm/vdisk.img')),$maps),'extra local disk');
$reject(fn()=>unmRecoveryAssertDiskMappings($xml($disk($target).$disk('/mnt/another-vm/vdisk.img','floppy')),$maps),'unmapped floppy');
$reject(fn()=>unmRecoveryAssertDiskMappings($xml($disk($target).$disk($target)),$maps),'duplicate writable disk');
$reject(fn()=>unmRecoveryAssertDiskMappings($xml($disk($target,'cdrom')),$maps),'attached CDROM');
$reject(fn()=>unmRecoveryAssertDiskMappings($xml(''),$maps),'missing mapped disk');
$reject(fn()=>unmRecoveryTransformDomainXml($xml($disk($source).str_replace('device="disk"','device = "disk"',$disk('/mnt/another-vm/vdisk.img'))),$maps),'regex whitespace bypass');
$reject(fn()=>unmRecoveryTransformDomainXml($xml($disk($source).'<disk type="file" device="cdrom"><source file="/mnt/isos/other.iso"></source></disk>'),$maps),'non-self-closing CDROM source');
echo "Recovery activation/retry exact disk binding regressions passed.\n";
