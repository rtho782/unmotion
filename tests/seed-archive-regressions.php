<?php
declare(strict_types=1);
$root=sys_get_temp_dir().'/unmotion-archive-test-'.bin2hex(random_bytes(6));mkdir($root,0700);mkdir($root.'/seeds',0700);
define('UNM_BOOT_DIR',$root);
function unmSeedPath(string $id):string {if(!preg_match('/^seed-[a-f0-9]{24}$/D',$id))throw new RuntimeException('Invalid id');return UNM_BOOT_DIR.'/seeds/'.$id;}
function unmSeed(string $id):array {return json_decode(file_get_contents(unmSeedPath($id).'/seed.json'),true,512,JSON_THROW_ON_ERROR);}
function unmAtomicJson(string $path,array $value):void {file_put_contents($path,json_encode($value));}
function archiveCheck(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
require __DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/seed-archive.php';
$id='seed-'.bin2hex(random_bytes(12));$dir=unmSeedPath($id);mkdir($dir,0700);
$seed=['id'=>$id,'vmUuid'=>'74100000-1111-4111-8111-000000000001','state'=>'FAILED'];
file_put_contents($dir.'/seed.json',json_encode($seed));file_put_contents($dir.'/request.cfg','ACTION="remove"');file_put_contents($dir.'/seed.log','Original failure and failed removal attempts');file_put_contents($dir.'/storage.tsv','');file_put_contents($dir.'/remove-source-candidates.txt',"pool/Squid Proxy\n");
try {
    archiveCheck(unmSeedNeverStarted($dir,$seed),'Legacy never-started removal rejected');
    foreach(['READY','INTERRUPTED','STARTING','TRANSFERRING'] as $state){$bad=$seed;$bad['state']=$state;archiveCheck(!unmSeedNeverStarted($dir,$bad),'Accepted non-failed state');}
    foreach(['generation'=>1,'pendingSnapshot'=>'unmotion-test','storage'=>[['destination'=>'pool/data']],'storageStarted'=>true] as $key=>$value){$bad=$seed;$bad[$key]=$value;archiveCheck(!unmSeedNeverStarted($dir,$bad),'Accepted storage evidence: '.$key);}
    file_put_contents($dir.'/storage.tsv',"image\t/mnt/a\t/mnt/b\n");archiveCheck(!unmSeedNeverStarted($dir,$seed),'Accepted nonempty transfer manifest');file_put_contents($dir.'/storage.tsv','');
    file_put_contents($dir.'/remote-capabilities.json','{}');archiveCheck(!unmSeedNeverStarted($dir,$seed),'Legacy preflight evidence ignored');
    $explicit=$seed+['executionSchema'=>1,'storageStarted'=>false,'executionBootId'=>unmSeedExecutionBootId()];archiveCheck(unmSeedNeverStarted($dir,$explicit),'Explicit pre-storage failure rejected');
    $rebooted=$explicit;$rebooted['executionBootId']='different-boot';archiveCheck(!unmSeedNeverStarted($dir,$rebooted),'Pre-storage proof crossed a reboot');unlink($dir.'/remote-capabilities.json');
    symlink($dir.'/request.cfg',$dir.'/unexpected');archiveCheck(!unmSeedNeverStarted($dir,$seed),'Accepted unexpected symlink');unlink($dir.'/unexpected');
    unlink($dir.'/storage.tsv');symlink($dir.'/request.cfg',$dir.'/storage.tsv');archiveCheck(!unmSeedNeverStarted($dir,$seed),'Accepted allowlisted symlink');unlink($dir.'/storage.tsv');file_put_contents($dir.'/storage.tsv','');
    unmSeedMarkStorageStarted($id);archiveCheck(unmArchiveUnstartedSeed($id)===null&&is_dir($dir),'Started transfer record was archived');file_put_contents($dir.'/seed.json',json_encode($seed));
    $busy=fopen('/var/lock/unmotion-seed-'.$id.'.lock','c');flock($busy,LOCK_EX);
    $denied=false;try{unmArchiveUnstartedSeed($id);}catch(RuntimeException $e){$denied=true;}finally{fclose($busy);}
    archiveCheck($denied&&is_dir($dir),'Active worker not excluded');
    $hashes=[];foreach(glob($dir.'/*') as $file)$hashes[basename($file)]=hash_file('sha256',$file);
    $result=unmArchiveUnstartedSeed($id);$archive=$result['archivePath'];
    archiveCheck($result['recordOnly']&&!is_dir($dir)&&is_dir($archive),'Failed record not archived');
    foreach($hashes as $name=>$hash)archiveCheck(hash_file('sha256',$archive.'/'.$name)===$hash,'Archive changed evidence');
    $worker=file_get_contents(__DIR__.'/../src/rootfs/usr/local/sbin/unmotion-seed-worker');
    archiveCheck(strpos($worker,'unmSeedMarkStorageStarted')<strpos($worker,'zfs snapshot "${snaps[@]}"'),'Storage boundary occurs after snapshots');
    archiveCheck(strpos($worker,'unmSeedMarkStorageStarted')<strpos($worker,'rsync -aS --inplace'),'Storage boundary occurs after copying');
    echo "Never-started seed archive, legacy evidence, partial-state exclusion, worker lock and unchanged-log regressions passed.\n";
} finally {
    foreach([$dir,$archive??''] as $folder)if($folder!==''&&is_dir($folder)){foreach(glob($folder.'/*')?:[] as $file)unlink($file);rmdir($folder);}
    if(is_dir($root.'/archived-seeds'))rmdir($root.'/archived-seeds');rmdir($root.'/seeds');rmdir($root);
    @unlink('/var/lock/unmotion-seed-'.$id.'.lock');
}
