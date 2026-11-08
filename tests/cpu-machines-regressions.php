<?php
// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
declare(strict_types=1);
require __DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/cpu-compatibility.php';
$root=sys_get_temp_dir().'/unmotion-machines-'.bin2hex(random_bytes(8));mkdir($root,0700);$checks=0;
function machineCheck(bool $ok,string $message):void{global $checks;++$checks;if(!$ok)throw new RuntimeException($message);}
function definition(string $name,string $machine,string $arch='x86_64',string $type='kvm'):string{return '<domain type="'.$type.'"><name>'.htmlspecialchars($name,ENT_XML1).'</name><os><type arch="'.$arch.'" machine="'.$machine.'">hvm</type></os></domain>';}
try{
    $empty=unmCpuCompatibilityMachines($root);machineCheck($empty['complete']&&array_column($empty['machines'],'id')===['q35','pc'],'Empty host has usable defaults without a live libvirt probe.');
    $missing=unmCpuCompatibilityMachines($root.'/missing');machineCheck(!$missing['complete']&&count($missing['machines'])===2&&$missing['error']!=='','Missing definitions are distinct from a successful empty host.');
    file_put_contents($root.'/squid.xml',definition('Squid Proxy','pc-q35-9.2'));
    file_put_contents($root.'/unicode.xml',definition(str_repeat('🐈',100),'pc-q35-9.2'));
    file_put_contents($root.'/current.xml',definition('<b>Newest & VM</b>','pc-q35-10.2'));
    file_put_contents($root.'/legacy.xml',definition('Legacy','pc-i440fx-9.2'));
    file_put_contents($root.'/alias.xml',definition('Alias VM','q35'));
    file_put_contents($root.'/arm.xml',definition('ARM','virt','aarch64'));
    file_put_contents($root.'/tcg.xml',definition('TCG','pc-q35-10.2','x86_64','qemu'));
    $before=hash_file('sha256',$root.'/squid.xml');$result=unmCpuCompatibilityMachines($root);$modes=array_column($result['machines'],null,'id');
    machineCheck($result['complete'],'Valid saved definitions produce complete choices.');
    machineCheck(count($modes)===5&&!isset($modes['virt']),'Only x86_64 KVM types are collected.');
    machineCheck(count($modes['pc-q35-9.2']['vms'])===2&&$modes['pc-q35-9.2']['vms'][0]==='Squid Proxy','Spaces and duplicate machine contexts preserve VM associations.');
    machineCheck($modes['pc-q35-10.2']['vms']===['<b>Newest & VM</b>'],'Names are data, not markup.');
    machineCheck($modes['q35']['vms']===['Alias VM'],'Existing aliases do not duplicate default options.');
    machineCheck(strlen($modes['pc-q35-9.2']['vms'][1])===320&&json_encode($result,JSON_THROW_ON_ERROR)!=='','Bounded VM names preserve UTF-8 characters.');
    machineCheck(array_search('pc-q35-10.2',array_keys($modes),true)<array_search('pc-q35-9.2',array_keys($modes),true),'Version sorting is natural, not lexical.');
    machineCheck(hash_file('sha256',$root.'/squid.xml')===$before,'Listing never changes saved VM definitions.');
    foreach(['q35','pc-q35-9.2','pc','pc-i440fx-10.2','pc-1.0'] as $machine)machineCheck(unmCpuCompatibilityMachineFamily($machine)!=='','Normal aliases and versioned families remain in scope.');
    foreach(['microvm','virt','q35-invalid','pc-q35-9.2-unknown','pc-i440fx-10.2/other'] as $machine)machineCheck(unmCpuCompatibilityMachineFamily($machine)==='','Other families and ambiguous suffixes are outside planned live-migration scope.');
    file_put_contents($root.'/custom.xml',definition('Custom Squid Proxy','microvm'));
    $scope=unmCpuCompatibilityVmScope($root);
    machineCheck($scope['complete']&&count($scope['unsupportedVms'])===3,'Other machine families, ARM and non-KVM VMs are explicitly out of scope.');
    machineCheck(in_array('Custom Squid Proxy',array_column($scope['unsupportedVms'],'name'),true),'Unsupported machines retain their exact VM association.');
    machineCheck(hash_file('sha256',$root.'/squid.xml')===$before,'Scope inspection changes no VM definition or existing move behavior.');
    unlink($root.'/custom.xml');
    file_put_contents($root.'/unsafe.xml','<!DOCTYPE domain [<!ENTITY x SYSTEM "file:///etc/passwd">]>'.definition('&x;','pc'));
    file_put_contents($root.'/invalid.xml',definition('Bad','q35;reboot'));
    file_put_contents($root.'/large.xml',str_repeat('x',2097153));
    symlink($root.'/squid.xml',$root.'/redirect.xml');
    $partial=unmCpuCompatibilityMachines($root);machineCheck(!$partial['complete']&&$partial['error']!==''&&count($partial['machines'])===5,'Malformed/unsafe/oversized/redirected definitions do not remove valid choices or imply a complete list.');
    symlink($root,$root.'-link');machineCheck(!unmCpuCompatibilityMachines($root.'-link')['complete'],'Redirected definitions directory is not scanned.');unlink($root.'-link');
    echo "Saved VM machine scope: $checks assertions passed.\n";
}finally{foreach(glob($root.'/*')?:[] as $file)unlink($file);rmdir($root);}
