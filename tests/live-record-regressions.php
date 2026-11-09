<?php
declare(strict_types=1);
require __DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/native-fence.php';
$base=sys_get_temp_dir().'/unmotion-live-record-'.bin2hex(random_bytes(8));mkdir($base,0700);$checks=0;
function check(bool $value,string $m):void{global $checks;if(!$value)throw new RuntimeException($m);$checks++;}
function deny(callable $f,string $m):void{try{$f();}catch(Throwable){check(true,$m);return;}throw new RuntimeException($m);}
$uuid='11111111-1111-4111-8111-000000000001';$source='11111111-1111-4111-8111-000000000002';$dest='11111111-1111-4111-8111-000000000003';$id='live-'.str_repeat('a',24);$path=$base.'/live-authority/'.$uuid.'.json';$marker=$base.'/live-managed/'.$uuid.'.json';
$record=['schemaVersion'=>1,'vmUuid'=>$uuid,'vmName'=>'Squid Proxy','jobId'=>$id,'role'=>'destination','state'=>'RECEIVING','diskPath'=>'/mnt/cache/domains/Squid Proxy/vdisk.qcow2','sourceHostId'=>$source,'destinationHostId'=>$dest,'bootId'=>'11111111-1111-4111-8111-000000000004','expiresAt'=>200];$m=['schemaVersion'=>1,'vmUuid'=>$uuid,'jobId'=>$id];file_put_contents($base.'/host_id',$dest);
try{
 check(unmLiveNativeRecord($base,$uuid)===null,'ordinary VM unaffected');unmNativeWrite($marker,$m);deny(fn()=>unmLiveNativeRecord($base,$uuid),'missing authority fenced');unmNativeWrite($path,$record);check(unmLiveNativeRecord($base,$uuid)===$record,'paired identity accepted');
 deny(fn()=>unmLiveNativeAssertRemovable($base),'unresolved removal refused');
 file_put_contents($path,'invalid');deny(fn()=>unmLiveNativeRecord($base,$uuid),'corrupt authority fenced');unmNativeWrite($path,$record);
 foreach(['jobId'=>'live-'.str_repeat('b',24),'vmUuid'=>$source,'role'=>'source','sourceHostId'=>$dest,'destinationHostId'=>$source,'state'=>'RETIRED','bootId'=>'not-a-boot','expiresAt'=>'200'] as $key=>$value){unmNativeWrite($path,array_replace($record,[$key=>$value]));deny(fn()=>unmLiveNativeRecord($base,$uuid),'wrong '.$key.' fenced');}unmNativeWrite($path,$record);
 unmNativeWrite($marker,array_replace($m,['jobId'=>'live-'.str_repeat('b',24)]));deny(fn()=>unmLiveNativeRecord($base,$uuid),'marker disagreement fenced');unmNativeWrite($marker,$m);
 file_put_contents($base.'/host_id',$source);deny(fn()=>unmLiveNativeRecord($base,$uuid),'restored onto another host fenced');file_put_contents($base.'/host_id',$dest);
 unlink($marker);deny(fn()=>unmLiveNativeRecord($base,$uuid),'missing marker fenced');unmNativeWrite($marker,$m);
 if(PHP_OS_FAMILY!=='Windows'){rename($path,$path.'.saved');symlink($path.'.saved',$path);deny(fn()=>unmLiveNativeRecord($base,$uuid),'redirected authority fenced');unlink($path);rename($path.'.saved',$path);}
 echo "Live authority record regressions passed: $checks checks.\n";
}finally{foreach([$path,$marker,$base.'/host_id'] as $p)if(is_file($p)||is_link($p))unlink($p);rmdir($base.'/live-authority');rmdir($base.'/live-managed');rmdir($base);}
