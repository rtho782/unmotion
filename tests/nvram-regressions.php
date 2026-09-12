<?php
declare(strict_types=1);
require_once __DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/lib.php';
function nvCheck(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
function nvReject(callable $test,string $message):void {try{$test();}catch(RuntimeException $e){return;}throw new RuntimeException('Accepted unsafe case: '.$message);}
$root='/mnt/unmotion-nvram-test-'.bin2hex(random_bytes(6));
if(!mkdir($root,0700))throw new RuntimeException('Run the NVRAM filesystem regression as root on the Linux development host.');
$directory=$root.'/Squid Proxy';mkdir($directory,0700);
$disk=$directory.'/disk.qcow2';$nvram=$directory.'/OVMF_VARS.fd';
file_put_contents($disk,'test disk');file_put_contents($nvram,random_bytes(131072));
$uuid='77f8e2ab-9ac5-4a11-b5f8-a1d3f6725b20';
$vm=['name'=>'Squid Proxy','uuid'=>$uuid,'nvram'=>$nvram];
$caps=['storage'=>['imageDirectory'=>'/mnt/destination/domains'],'features'=>['customNvramMigration'=>true]];
$maps=[['source'=>$disk,'destination'=>'/mnt/destination/domains/Squid Proxy/disk.qcow2','bundled'=>true]];
try{
    $plan=unmMigrationNvramPlan($vm,$maps,$caps,false);
    nvCheck($plan['destination']==='/mnt/destination/domains/Squid Proxy/OVMF_VARS.fd','dataset NVRAM mapping');
    nvCheck($plan['bundled']===true,'dataset uses existing transfer');
    $maps[0]['bundled']=false;
    nvCheck(unmMigrationNvramPlan($vm,$maps,$caps,false)['bundled']===false,'file-copy fallback');
    nvReject(fn()=>unmMigrationNvramPlan($vm,$maps,['storage'=>$caps['storage'],'features'=>[]],false),'old peer');
    nvReject(fn()=>unmMigrationNvramPlan($vm,[],$caps,false),'no sibling disk');
    nvReject(fn()=>unmMigrationNvramPlan($vm,array_merge($maps,[['source'=>$disk,'destination'=>'/mnt/destination/domains/Other/disk.qcow2']]),$caps,false),'ambiguous destinations');
    nvReject(fn()=>unmMigrationNvramPlan($vm,[['source'=>$disk,'destination'=>'/mnt/destination/domains/Squid Proxy/OVMF_VARS.fd']],$caps,false),'destination disk collision');
    nvReject(fn()=>unmMigrationNvramPlan(array_merge($vm,['name'=>'Wrong VM']),$maps,$caps,false),'wrong VM directory');
    nvReject(fn()=>unmMigrationNvramPlan(array_merge($vm,['nvram'=>$directory.'/missing.fd']),$maps,$caps,false),'missing variables');
    symlink($nvram,$directory.'/link.fd');
    nvReject(fn()=>unmMigrationNvramPlan(array_merge($vm,['nvram'=>$directory.'/link.fd']),$maps,$caps,false),'symlink file');
    unlink($directory.'/link.fd');
    link($nvram,$directory.'/hardlink.fd');
    nvReject(fn()=>unmMigrationNvramPlan($vm,$maps,$caps,false),'hard-linked variables');
    unlink($directory.'/hardlink.fd');
    foreach(['/mnt/user/domains/Squid Proxy/OVMF_VARS.fd','/mnt/cache/../etc/x.fd',"/mnt/cache/a\tb.fd",'/mnt/cache//x.fd'] as $bad)nvCheck(!unmNvramPoolPath($bad),'unsafe path '.$bad);
    $escaped=$directory.'/UEFI & VARS.fd';rename($nvram,$escaped);
    $vm['nvram']=$escaped;
    $plan=unmMigrationNvramPlan($vm,$maps,$caps,false);
    $sourceXml=$root.'/source.xml';$destXml=$root.'/destination.xml';$mapping=$root.'/mapping.tsv';
    file_put_contents($sourceXml,"<domain type='kvm'><name>Squid Proxy</name><uuid>$uuid</uuid><os><nvram format='raw'>".htmlspecialchars($escaped,ENT_XML1)."</nvram></os><devices><disk type='file' device='disk'><source file='".htmlspecialchars($disk,ENT_QUOTES|ENT_XML1)."'/></disk></devices></domain>");
    file_put_contents($mapping,"disk\t$disk\t".$maps[0]['destination']."\nnvram\t$escaped\t".$plan['destination']."\n");
    $result=unmRun(['php',__DIR__.'/../src/rootfs/usr/local/sbin/unmotion-transform',$sourceXml,$destXml,$mapping,'remove','1'],null,15);
    nvCheck($result['code']===0,'transform succeeded: '.$result['stderr']);
    $doc=new DOMDocument();nvCheck($doc->load($destXml),'output parses');
    $xp=new DOMXPath($doc);
    nvCheck($xp->evaluate('string(/domain/os/nvram)')===$plan['destination'],'escaped NVRAM path transformed');
    nvCheck($xp->evaluate('string(/domain/os/nvram/@format)')==='raw','format preserved');
    nvCheck($xp->evaluate('string(/domain/devices/disk/source/@file)')===$maps[0]['destination'],'disk mapping preserved');
    nvCheck($xp->evaluate('string(/domain/uuid)')===$uuid,'UUID preserved');
    echo "Custom NVRAM mapping and filesystem regressions passed.\n";
}finally{
    // Only this test's exact random fixture files, never a recursive deletion.
    foreach(glob($directory.'/*')?:[] as $path)unlink($path);
    rmdir($directory);foreach(glob($root.'/*')?:[] as $path)unlink($path);rmdir($root);
}
