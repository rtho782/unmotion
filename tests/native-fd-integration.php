<?php
// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
declare(strict_types=1);
function fdWrite($s,string $v):void{while($v!==''){$n=@fwrite($s,$v);if($n===false||$n===0)throw new RuntimeException('Test write failed');$v=substr($v,$n);}}
$mode=$argv[1]??'';
if($mode==='--producer'){for($i=0;$i<128;$i++)fdWrite(STDOUT,str_repeat("\x00\xff\x91\x63",16384));exit;}
if($mode==='--consumer'){$hash=hash_init('sha256');$bytes=hash_update_stream($hash,STDIN);echo json_encode(['bytes'=>$bytes,'sha256'=>hash_final($hash)]);exit;}
if($mode==='--backend'){$path=$argv[2];$s=stream_socket_server('unix://'.$path,$errno,$error);chmod($path,0600);$c=stream_socket_accept($s,5);if(!$c)exit(3);stream_set_timeout($c,5);$h=hash_init('sha256');$n=hash_update_stream($h,$c);if($n!==8388608||hash_final($h)!==hash('sha256',str_repeat("\x00\xff\x91\x63",2097152)))exit(4);fdWrite($c,str_repeat("\x12\x00\xf1\x98",524288));fclose($c);fclose($s);exit;}
require __DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/lib.php';
$binary=$argv[1]??'';if(!is_file($binary)||!is_executable($binary))throw new RuntimeException('Compiled Go test binary required');
$dir=sys_get_temp_dir().'/unmotion-native-fd-'.bin2hex(random_bytes(8));mkdir($dir,0700);$lease=$dir.'/lease';file_put_contents($lease,'ACTIVE');$processes=[];$resources=[];
try{
 $backend=proc_open([PHP_BINARY,__FILE__,'--backend',$dir.'/backend.sock'],[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file',$dir.'/backend.log','a']],$unused);$processes[]=$backend;
 $producer=proc_open([PHP_BINARY,__FILE__,'--producer'],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['file',$dir.'/producer.log','a']],$in);$processes[]=$producer;
 $consumer=proc_open([PHP_BINARY,__FILE__,'--consumer'],[0=>['pipe','r'],1=>['pipe','w'],2=>['file',$dir.'/consumer.log','a']],$out);$processes[]=$consumer;
 for($i=0;$i<100&&!file_exists($dir.'/backend.sock');$i++)usleep(10000);$socket=stream_socket_client('unix://'.$dir.'/backend.sock',$errno,$error,3);if(!$socket)throw new RuntimeException('Test backend unavailable');
 $lock=fopen($dir.'/must-not-inherit.lock','c');flock($lock,LOCK_EX);$stderr=fopen($dir.'/native.log','a');
 $child=proc_open([$binary,'-test.run=^TestPumpChild$'],[0=>$in[1],1=>$out[0],2=>$stderr,3=>$socket,4=>$socket,5=>['pipe','r'],6=>['pipe','w']],$control,null,['PATH'=>(string)getenv('PATH'),'UNM_NATIVE_TEST_CHILD'=>'1','UNM_NATIVE_TEST_LEASE'=>$lease,'UNM_NATIVE_TEST_MODE'=>'duplex']);$processes[]=$child;
 foreach([$in[1],$out[0],$socket,$stderr,$lock] as $r)fclose($r);$resources=[$out[1],$control[5],$control[6]];stream_set_blocking($control[6],false);
 $deadline=microtime(true)+10;$code=null;$lockTest=fopen($dir.'/must-not-inherit.lock','c');$released=false;
 do{stream_get_contents($control[6]);if(!$released&&flock($lockTest,LOCK_EX|LOCK_NB)){$released=true;flock($lockTest,LOCK_UN);}$s=proc_get_status($child);if(!$s['running']){$code=$s['exitcode'];break;}if(microtime(true)>$deadline)throw new RuntimeException('PHP/native FD integration timed out');usleep(10000);}while(true);fclose($lockTest);
 if(!$released)throw new RuntimeException('Native child inherited a private parent lock');if($code!==0)throw new RuntimeException('Native child failed: '.file_get_contents($dir.'/native.log'));
 fclose($control[5]);fclose($control[6]);$result=json_decode(stream_get_contents($out[1]),true);fclose($out[1]);$resources=[];
 if(($result['bytes']??0)!==2097152||($result['sha256']??'')!==hash('sha256',str_repeat("\x12\x00\xf1\x98",524288)))throw new RuntimeException('Response binary integrity failed');
 foreach($processes as $p){$r=proc_close($p);if($r!==0&&$r!==-1)throw new RuntimeException('Test peer failed');}$processes=[];
 echo "Real PHP-to-native descriptor handoff, binary integrity, half-close and inherited-lock isolation passed.\n";
}finally{foreach($resources as $r)if(is_resource($r))fclose($r);foreach($processes as $p)if(is_resource($p))proc_close($p);foreach(glob($dir.'/*')?:[] as $p)unlink($p);rmdir($dir);}
