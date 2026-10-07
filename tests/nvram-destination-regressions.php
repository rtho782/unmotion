<?php
declare(strict_types=1);
// Post-recovery isolated destination guard tests; no libvirt or host config writes.
$root='/mnt/unmotion-nvram-destination-'.bin2hex(random_bytes(6));
$inventory=[];$inventoryFail=false;
function unmLoadConfig():array {return ['image_dir'=>$GLOBALS['root']];}
function unmPathWithin(string $path,string $root):bool {return $path===$root||str_starts_with($path,rtrim($root,'/').'/');}
function unmRun(array $args,mixed $input=null,int $timeout=20):array {return ['code'=>$GLOBALS['inventoryFail']?1:0,'stdout'=>implode("\n",array_keys($GLOBALS['inventory']))];}
function unmDomainXml(string $uuid,bool $inactive=true):string {return $GLOBALS['inventory'][$uuid][$inactive?'inactive':'live'];}
require __DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/migration-nvram.php';
function reject(callable $action,string $case):void {try{$action();}catch(RuntimeException $e){return;}throw new RuntimeException('Accepted unsafe case: '.$case);}
mkdir($root,0700);$dir=$root.'/Squid Proxy';mkdir($dir,0700);$target=$dir.'/OVMF_VARS.fd';
$plan=['kind'=>'custom','destination'=>$target,'diskDestination'=>$dir.'/disk.img','vmUuid'=>'207ba0c0-ae4a-4d7e-90a6-7dde844267cd','vmName'=>'Squid Proxy','bundled'=>false,'sha256'=>hash('sha256','firmware')];
try {
    unmMigrationNvramDestination($plan);
    file_put_contents($target,'firmware');
    reject(fn()=>unmMigrationNvramDestination($plan),'existing file');
    unmMigrationNvramDestination($plan,true);
    reject(fn()=>unmMigrationNvramDestination(array_replace($plan,['sha256'=>str_repeat('0',64)]),true),'checksum mismatch');
    unmMigrationNvramDestination(array_replace($plan,['bundled'=>true]));
    reject(fn()=>unmMigrationNvramDestination(array_replace($plan,['destination'=>$root.'/../escape.fd'])),'traversal');
    reject(fn()=>unmMigrationNvramDestination(array_replace($plan,['diskDestination'=>$target])),'disk collision');
    reject(fn()=>unmMigrationNvramDestination(array_replace($plan,['vmName'=>'Not Squid'])),'directory identity');
    $inventoryFail=true;reject(fn()=>unmMigrationNvramDestination($plan,true),'inventory unavailable');$inventoryFail=false;
    $other='7a848660-defb-4364-b108-5ea731c08ab8';
    $inventory[$other]=['inactive'=>'<domain><devices/></domain>','live'=>"<domain><devices><disk><source file='$dir/disk.img'/></disk></devices></domain>"];
    reject(fn()=>unmMigrationNvramDestination($plan,true),'other live VM owns directory');
    $inventory[$other]['inactive']="<domain><os><nvram type='file'><source file='$target'/></nvram></os></domain>";
    $inventory[$other]['live']='<domain/>';
    reject(fn()=>unmMigrationNvramDestination($plan,true),'other inactive VM owns nested NVRAM');$inventory=[];
    unlink($target);symlink('/dev/null',$target);
    reject(fn()=>unmMigrationNvramDestination(array_replace($plan,['bundled'=>true])),'symlink destination');unlink($target);
    rmdir($dir);symlink($root,$dir);reject(fn()=>unmMigrationNvramDestination($plan),'symlink parent');unlink($dir);mkdir($dir);
    foreach(["<domain><os><nvram/></os></domain>","<domain><os><nvram type='file'><source file='/tmp/a'/></nvram></os></domain>","<!DOCTYPE domain><domain/>"] as $xml)reject(fn()=>unmMigrationNvramXml($xml),'unsupported XML');
    if(unmMigrationNvramXml("<domain><os><nvram>/mnt/a &amp; b.fd</nvram></os></domain>")!=='/mnt/a & b.fd')throw new RuntimeException('XML escaping');
    echo "Destination NVRAM ownership, collision, checksum and XML regressions passed.\n";
} finally {
    if(file_exists($target)||is_link($target))unlink($target);
    if(is_link($dir))unlink($dir);elseif(is_dir($dir))rmdir($dir);
    rmdir($root);
}
