<?php
// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
declare(strict_types=1);
require_once __DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/lib.php';

// Exercise the real planning function in an isolated namespace. Filesystem
// validation is covered by nvram-regressions.php; here standard libvirt paths
// are fixtures, so this test never writes the host's /etc or /var/lib/libvirt.
$source=(string)file_get_contents(__DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/migration-nvram.php');
if(!preg_match('/function unmMigrationNvramPlan\(.*?\n\}\n/s',$source,$match))throw new RuntimeException('NVRAM plan function not found');
eval('namespace UnmotionNvramProtocolRegression; use RuntimeException;
function unmNvramRegularFile(string $path,bool $pool): void {if($pool)throw new RuntimeException("Expected a standard libvirt path");}
function filesize(string $path): int {return 131072;}
function unmTransportHostStatePath(string $path,string $uuid): bool {return \\unmTransportHostStatePath($path,$uuid);}
'.$match[0]);
$uuid='77f8e2ab-9ac5-4a11-b5f8-a1d3f6725b20';
$caps=['features'=>['restrictedSshTransport'=>true]];
foreach(['/etc/libvirt/qemu/nvram/','/var/lib/libvirt/qemu/nvram/'] as $directory){
    foreach(['_VARS.fd','_VARS-pure-efi-tpm.fd'] as $suffix){
        $scoped=$directory.$uuid.$suffix;
        $vm=['uuid'=>$uuid,'name'=>'Squid Proxy','nvram'=>$scoped];
        $plan=UnmotionNvramProtocolRegression\unmMigrationNvramPlan($vm,[],$caps,false);
        if($plan!==['kind'=>'legacy','source'=>$scoped,'destination'=>$scoped,'bundled'=>false,'bytes'=>131072])throw new RuntimeException('UUID-scoped legacy NVRAM should retain its mapping: '.$suffix);
    }
    foreach([$directory.'Squid Proxy_VARS.fd',$directory.'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa_VARS.fd'] as $named){
        $vm['nvram']=$named;$rejected=false;
        try{UnmotionNvramProtocolRegression\unmMigrationNvramPlan($vm,[],$caps,false);}catch(RuntimeException $e){$rejected=str_contains($e->getMessage(),'UUID-scoped');}
        if(!$rejected)throw new RuntimeException('Restricted transport accepted non-owned NVRAM: '.$named);
        $legacyPlan=UnmotionNvramProtocolRegression\unmMigrationNvramPlan($vm,[],[],false);
        if($legacyPlan['kind']!=='legacy'||$legacyPlan['destination']!==$named)throw new RuntimeException('Capabilities-absent historical NVRAM mapping changed');
    }
}
echo "Protocol-7 UUID-scoped NVRAM and historical capability-free planning regressions passed.\n";
