<?php
// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
declare(strict_types=1);
require_once __DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/process-identity.php';
require_once __DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/process-upgrade.php';
function check(bool $condition,string $message): void {if(!$condition)throw new RuntimeException($message);}
$boot=['executable'=>'/usr/bin/bash','argv'=>['/bin/bash','/etc/rc.d/rc.local']];
check(unmProcessEarlyBootEvidence([$boot],[],false),'Real early boot may restore package before libvirt exists.');
check(!unmProcessEarlyBootEvidence([$boot],[],true),'Already installed package is an upgrade, not boot restore.');
check(!unmProcessEarlyBootEvidence([],[],false),'Manual install without libvirt remains fail-closed.');
$bait=['executable'=>'/usr/bin/php','argv'=>['php','-r','/etc/rc.d/rc.local']];
check(!unmProcessEarlyBootEvidence([$bait],[],false),'Incidental boot-script text is not ancestry.');
foreach(['qemu-system-x86_64','qemu-kvm','kvm','libvirtd','virtqemud','emhttpd','emhttp'] as $name){
    check(!unmProcessEarlyBootEvidence([$boot],[['executable'=>'/usr/sbin/'.$name]],false),'Live '.$name.' prevents boot exemption.');
}
check(!unmProcessEarlyBootRestore(),'Regression runner is not a boot-script descendant.');
$dir=sys_get_temp_dir().'/unmotion-boot-'.bin2hex(random_bytes(8));mkdir($dir,0700);mkdir($dir.'/native-managed',0700);
try {
    file_put_contents($dir.'/native-managed/broken.json','{}');
    try{unmProcessAssertManagedGuestsStopped($dir,true);throw new LogicException('Corrupt authority bypassed during boot.');}catch(RuntimeException $expected){}
} finally {unlink($dir.'/native-managed/broken.json');rmdir($dir.'/native-managed');rmdir($dir);}
echo "Early-boot ancestry, no-running-host proof, normal upgrade exclusion and corrupt authority regressions passed.\n";
