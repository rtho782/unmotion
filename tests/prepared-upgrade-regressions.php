<?php
// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
declare(strict_types=1);
require __DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/process-upgrade.php';
$root=sys_get_temp_dir().'/unmotion-prepared-upgrade-'.bin2hex(random_bytes(8));$dir=$root.'/seeds/seed-fixture';mkdir($dir,0700,true);$checks=0;
function rejectedUpgrade(callable $f,string $message):void{global $checks;try{$f();}catch(RuntimeException){++$checks;return;}throw new RuntimeException($message);}
try{
    file_put_contents($dir.'/seed.json',json_encode(['state'=>'READY','storage'=>[['source'=>'pool/Squid Proxy']]]));
    file_put_contents($dir.'/remote-capabilities.json','{"protocolVersion":5}');
    rejectedUpgrade(fn()=>unmProcessAssertPreparedCompatible($root,7),'Protocol-5 preparation did not block an incompatible upgrade');
    file_put_contents($dir.'/remote-capabilities.json','{"protocolVersion":7}');unmProcessAssertPreparedCompatible($root,7);++$checks;
    rejectedUpgrade(fn()=>unmProcessAssertPreparedCompatible($root,8),'Future incompatible wire upgrade ignored retained data');
    unlink($dir.'/remote-capabilities.json');rejectedUpgrade(fn()=>unmProcessAssertPreparedCompatible($root,7),'Unknown ownership accepted');
    file_put_contents($dir.'/seed.json','{"state":"FAILED","storageStarted":false}');unmProcessAssertPreparedCompatible($root,7);++$checks;
    file_put_contents($dir.'/seed.json','{"state":"REMOVED","protocolVersion":5,"generation":1}');unmProcessAssertPreparedCompatible($root,7);++$checks;
    file_put_contents($dir.'/seed.json','bad json');rejectedUpgrade(fn()=>unmProcessAssertPreparedCompatible($root,7),'Corrupt record ignored');
    $source=file_get_contents(__DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/process-upgrade.php');
    if(!str_contains($source,'if(!$bootRestore&&$installedProtocol!==7)'))throw new RuntimeException('Boot restoration/same-protocol cleanup upgrades must remain possible');++$checks;
    echo "$checks prepared/partial protocol-boundary and boot-restoration guards passed.\n";
}finally{foreach(glob($dir.'/*')?:[] as $file)unlink($file);rmdir($dir);rmdir(dirname($dir));rmdir($root);}
