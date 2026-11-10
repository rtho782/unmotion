<?php
// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
declare(strict_types=1);
require __DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/lib.php';
$root=sys_get_temp_dir().'/unmotion-retirement-remove-'.bin2hex(random_bytes(8));$checks=0;
$paths=[$root.'/replications/repl-test/retirement.json',$root.'/replicas/host-test/repl-test/retirement.json',$root.'/seeds/seed-test/cleanup.json',$root.'/cleanup-receipts/peer-test/seed-test.json'];
function refuseRetirementRemoval(string $root):void{global $checks;try{unmNativeAssertRemovable($root);}catch(RuntimeException){++$checks;return;}throw new RuntimeException('Uninstall accepted unresolved cleanup or kept replica ownership');}
try{
 foreach($paths as $path)mkdir(dirname($path),0700,true);
 foreach(['ENDING','BLOCKED','ENDED_KEEP'] as $state){file_put_contents($paths[0],json_encode(['state'=>$state]));refuseRetirementRemoval($root);}
 file_put_contents($paths[0],'{"state":"ENDED_DELETE"}');unmNativeAssertRemovable($root);++$checks;
 file_put_contents($paths[1],'{"state":"KEPT"}');refuseRetirementRemoval($root);file_put_contents($paths[1],'{"state":"DELETED"}');unmNativeAssertRemovable($root);++$checks;
 file_put_contents($paths[2],'{"state":"SOURCE_CLEANUP"}');refuseRetirementRemoval($root);file_put_contents($paths[2],'{"state":"COMPLETE"}');unmNativeAssertRemovable($root);++$checks;
 file_put_contents($paths[3],'{"state":"CLEANING"}');refuseRetirementRemoval($root);file_put_contents($paths[3],'{"state":"DELETED"}');unmNativeAssertRemovable($root);++$checks;
 file_put_contents($paths[3],'invalid json');refuseRetirementRemoval($root);
 echo "$checks retirement/retained-data/cleanup uninstall guards passed.\n";
}finally{
 foreach($paths as $path)if(is_file($path))unlink($path);
 $dirs=[];foreach($paths as $path)for($d=dirname($path);$d!==$root;$d=dirname($d))$dirs[$d]=true;
 $dirs=array_keys($dirs);usort($dirs,fn($a,$b)=>strlen($b)<=>strlen($a));foreach($dirs as $dir)rmdir($dir);rmdir($root);
}
