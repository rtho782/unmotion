<?php
declare(strict_types=1);
// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
require_once __DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/transport.php';
$uuid='11111111-2222-4333-8444-555555555555';$passed=0;
function firmwareCheck(bool $ok,string $label):void {global $passed;if(!$ok)throw new RuntimeException($label);$passed++;}
function firmwareDeny(callable $call,string $label,string $message=''):void {
    try{$call();}catch(RuntimeException $e){firmwareCheck($message===''||str_contains($e->getMessage(),$message),$label.': '.$e->getMessage());return;}
    throw new RuntimeException('Accepted: '.$label);
}
$base="<domain type='kvm' xmlns:qemu='http://libvirt.org/schemas/domain/qemu/1.0'><name>Squid Proxy</name><uuid>$uuid</uuid><metadata><vmtemplate xmlns='unraid' name='Windows Server 2016'/></metadata><os><type arch='x86_64' machine='pc-q35-9.2'>hvm</type><loader readonly='yes' type='pflash'>/usr/share/qemu/ovmf-x64/OVMF_CODE-pure-efi.fd</loader><nvram>/etc/libvirt/qemu/nvram/{$uuid}_VARS.fd</nvram></os><devices><emulator>/usr/local/sbin/qemu</emulator><disk type='file' device='disk'><driver name='qemu' type='raw'/><source file='/mnt/cache/domains/Squid Proxy/vdisk.raw'/><target dev='vda' bus='virtio'/></disk></devices></domain>";
$wrap=static fn(string $extension):string=>str_replace('</domain>',$extension.'</domain>',$base);
$setting=static fn(string $value):string=>"<qemu:commandline><qemu:arg value='-fw_cfg'/><qemu:arg value='$value'/></qemu:commandline>";
$good=$setting('opt/ovmf/X-PciMmio64Mb,string=65536');
$caps=['protocolVersion'=>7,'firmwareSettings'=>['ovmfPciMmio64'=>true]];
foreach(['0','1','65536','131072','16777216'] as $number){
    foreach(['','name='] as $prefix){
        $xml=$wrap($setting($prefix.'opt/ovmf/X-PciMmio64Mb,string='.$number));$hash=hash('sha256',$xml);
        unmTransportValidateDomainXml($xml,$uuid,null,$caps);
        firmwareCheck(hash('sha256',$xml)===$hash,'firmware value accepted without XML rewriting');
    }
}
unmTransportValidateDomainXml($base,$uuid,null,[]);firmwareCheck(true,'ordinary XML needs no new destination capability');
foreach([[],['firmwareSettings'=>['ovmfPciMmio64'=>false]]] as $oldCaps)
    firmwareDeny(fn()=>unmTransportValidateDomainXml($wrap($good),$uuid,null,$oldCaps),'older destination blocked in source preflight','Update unMotion on both hosts');
unmTransportValidateDomainXml(str_replace(['xmlns:qemu=','qemu:'],['xmlns:alias=','alias:'],$wrap($good)),$uuid,null);
firmwareCheck(true,'namespace URI, not prefix, identifies QEMU extensions');
$default="<commandline xmlns='http://libvirt.org/schemas/domain/qemu/1.0'><arg value='-fw_cfg'/><arg value='opt/ovmf/X-PciMmio64Mb,string=65536'/></commandline>";
unmTransportValidateDomainXml($wrap($default),$uuid,null);firmwareCheck(true,'local default QEMU namespace accepted');
unmTransportValidateDomainXml($wrap(str_replace('<qemu:arg',"\n<!-- firmware setting -->\n<qemu:arg",$good)),$uuid,null);
firmwareCheck(true,'formatting whitespace and comments accepted');
foreach(['','-1','+1','01','0x10000','1.0','1e5',' 65536','65536 ','16777217','9999999999999999999999999999999999',"65536&#10;",'65536,file=/etc/shadow','65536,gen_id=secret','65536,,file=/etc/shadow'] as $badNumber)
    firmwareDeny(fn()=>unmTransportValidateDomainXml($wrap($setting('opt/ovmf/X-PciMmio64Mb,string='.$badNumber)),$uuid,null),'malformed or unbounded number '.$badNumber);
foreach(['opt/ovmf/X-PciMmio64Mb,file=/etc/shadow','opt/ovmf/X-PciMmio64Mb,gen_id=secret','opt/ovmf/Other,string=65536','opt/ovmf/X-PciMmio64Mb,string=65536,string=1','opt/ovmf/X-PciMmio64Mb,string=65536,name=other','opt/ovmf/X-PciMmio64Mb,string=65536;touch /tmp/x'] as $value)
    firmwareDeny(fn()=>unmTransportValidateDomainXml($wrap($setting($value)),$uuid,null),'nonliteral firmware source or extra option');
$attacks=[
    str_replace('-fw_cfg','-chardev',$good),
    str_replace("value='-fw_cfg'","value='-fw_cfg' extra='ignored'",$good),
    str_replace("value='-fw_cfg'","qemu:value='-fw_cfg'",$good),
    str_replace('<qemu:commandline>','<qemu:commandline extra="ignored">',$good),
    str_replace('</qemu:commandline>',"<qemu:arg value='-chardev'/><qemu:arg value='file,path=/etc/shadow'/></qemu:commandline>",$good),
    str_replace('</qemu:commandline>',"<qemu:env name='LD_PRELOAD' value='/tmp/library.so'/></qemu:commandline>",$good),
    str_replace('</qemu:commandline>',"<qemu:arg value='extra'/></qemu:commandline>",$good),
    str_replace("<qemu:arg value='-fw_cfg'/>",'',$good),
    str_replace("<qemu:arg value='-fw_cfg'/>","<qemu:arg value='-fw_cfg'><qemu:arg value='-chardev'/></qemu:arg>",$good),
    str_replace('</qemu:commandline>','unexpected text</qemu:commandline>',$good),
    str_replace('</qemu:commandline>','<?unexpected data?></qemu:commandline>',$good),
    str_replace('qemu:arg','arg',$good),
    $good.$good,
    $good."<qemu:override/>",
    $good."<qemu:capabilities><qemu:del capability='blockdev'/></qemu:capabilities>",
    "<qemu:commandline/>",
];
foreach($attacks as $index=>$extension)firmwareDeny(fn()=>unmTransportValidateDomainXml($wrap($extension),$uuid,null),'unsupported command-line shape '.$index);
firmwareDeny(fn()=>unmTransportValidateDomainXml(str_replace('</devices>',$good.'</devices>',$base),$uuid,null),'command line must be a direct domain child');
firmwareDeny(fn()=>unmTransportValidateDomainXml(str_replace('http://libvirt.org/schemas/domain/qemu/1.0','http://attacker.invalid/qemu',$wrap($good)),$uuid,null),'lookalike namespace rejected');
firmwareDeny(fn()=>unmTransportValidateDomainXml(str_replace('/mnt/cache/domains/Squid Proxy/vdisk.raw','/etc/shadow',$wrap($good)),$uuid,null),'firmware exception does not bypass disk safety');
$lib=file_get_contents(__DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/lib.php');
firmwareCheck(str_contains($lib,"'firmwareSettings'=>['ovmfPciMmio64'=>true]"),'capability advertised');
firmwareCheck(str_contains($lib,'unmTransportValidateDomainXml($sourceXml,(string)$local[\'uuid\'],null,$caps);'),'migration preflight checks destination capability');
firmwareCheck(str_contains($lib,'unmTransportValidateDomainXml(unmDomainXml((string)$vm[\'uuid\'],true),(string)$vm[\'uuid\'],null,$caps);'),'replication preflight checks destination capability');
foreach(['unmotion-worker','unmotion-seed-worker','unmotion-replication-worker'] as $worker){
    $source=file_get_contents(__DIR__.'/../src/rootfs/usr/local/sbin/'.$worker);
    firmwareCheck((bool)preg_match('/unmTransportValidateDomainXml\([^;]+null,unmLoadJson\(\$argv\[[23]\]\)\)/',$source),'fresh worker XML/capability check: '.$worker);
}
echo "OVMF firmware regressions: $passed assertions passed\n";
