<?php
// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
declare(strict_types=1);
require __DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/lib.php';
set_exception_handler(function(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);});
if(PHP_OS_FAMILY!=='Linux'||posix_geteuid()!==0){echo "SKIP: isolated native SSH integration requires Linux root.\n";exit;}
$sshd='/usr/sbin/sshd';if(!is_executable($sshd))throw new RuntimeException('OpenSSH server required for native key-option integration');
$dir=sys_get_temp_dir().'/unmotion-native-ssh-'.bin2hex(random_bytes(6));mkdir($dir,0700);$server=null;$client=null;$listener=null;$checks=0;
function sshCheck(bool $ok,string $m):void{global $checks;if(!$ok)throw new RuntimeException($m);$checks++;}
function sshStop(&$p):void{if(is_resource($p)){if(proc_get_status($p)['running'])proc_terminate($p);proc_close($p);}$p=null;}
try{
 foreach(['host','client'] as $name){$r=unmLiveRunCommand(['ssh-keygen','-q','-t','ed25519','-N','','-f',$dir.'/'.$name]);sshCheck($r['code']===0,'Isolated SSH key generated');}
 $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);if(!$socket)throw new RuntimeException($error);$port=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1);fclose($socket);
 $public=trim(file_get_contents($dir.'/client.pub'));$j=['id'=>'live-'.bin2hex(random_bytes(12)),'nativeHandoverVersion'=>1];$key=['publicKey'=>$public,'keyId'=>unmManagedSshKeyId($public),'sourceAddress'=>'127.0.0.1','expiresAt'=>time()+120];
 file_put_contents($dir.'/authorized_keys',unmLiveTrustedKeyLine($j,$key)."\n");chmod($dir.'/authorized_keys',0600);
 // This isolated daemon uses a root-private directory under sticky /tmp,
 // not the host's normal authorised-key path or sshd configuration.
 file_put_contents($dir.'/sshd_config',"Port $port\nListenAddress 127.0.0.1\nHostKey $dir/host\nPidFile $dir/sshd.pid\nAuthorizedKeysFile $dir/authorized_keys\nPermitRootLogin yes\nPasswordAuthentication no\nKbdInteractiveAuthentication no\nPubkeyAuthentication yes\nUsePAM no\nStrictModes no\nAllowTcpForwarding yes\nAllowStreamLocalForwarding yes\nLogLevel VERBOSE\n");
 file_put_contents($dir.'/known_hosts','[127.0.0.1]:'.$port.' '.trim(file_get_contents($dir.'/host.pub'))."\n");
 file_put_contents($dir.'/sshd_config',"PerSourcePenalties no\n",FILE_APPEND);
 $server=proc_open([$sshd,'-D','-e','-f',$dir.'/sshd_config'],[0=>['file','/dev/null','r'],1=>['file',$dir.'/server.log','a'],2=>['file',$dir.'/server.log','a']],$unused);if(!$server)throw new RuntimeException('Isolated sshd did not launch');
 $until=microtime(true)+5;do{$probe=@stream_socket_client('tcp://127.0.0.1:'.$port,$n,$e,0.1);if($probe){fclose($probe);break;}if(microtime(true)>$until)throw new RuntimeException('Isolated sshd not ready: '.file_get_contents($dir.'/server.log'));usleep(10000);}while(true);
 $base=['/usr/bin/ssh','-F','/dev/null','-T','-p',(string)$port,'-i',$dir.'/client','-o','BatchMode=yes','-o','IdentitiesOnly=yes','-o','IdentityAgent=none','-o','StrictHostKeyChecking=yes','-o','UserKnownHostsFile='.$dir.'/known_hosts','-o','GlobalKnownHostsFile=/dev/null','-o','ConnectTimeout=3'];
 foreach(['aes128-gcm@openssh.com','aes256-gcm@openssh.com'] as $cipher){
  $remote=$dir.'/receiver.sock';$local=$dir.'/forward.sock';$listener=stream_socket_server('unix://'.$remote,$n,$e);if(!$listener)throw new RuntimeException($e);
  $args=array_merge($base,['-v','-c',$cipher,'-N','-o','ExitOnForwardFailure=yes','-o','StreamLocalBindMask=0177','-L',$local.':'.$remote,'root@127.0.0.1']);
  $client=proc_open($args,[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file',$dir.'/client.log','w']],$unused);if(!$client)throw new RuntimeException('SSH client unavailable');
  $until=microtime(true)+5;while(!file_exists($local)){if(microtime(true)>$until||!proc_get_status($client)['running'])throw new RuntimeException('Native key rejected: '.file_get_contents($dir.'/client.log').' Server: '.file_get_contents($dir.'/server.log'));usleep(10000);}
  $send=stream_socket_client('unix://'.$local,$n,$e,3);if(!$send)throw new RuntimeException($e);stream_set_timeout($send,3);fwrite($send,"native-test\n");$receive=stream_socket_accept($listener,3);if(!is_resource($receive))throw new RuntimeException('Native Unix forwarding rejected. Client: '.file_get_contents($dir.'/client.log').' Server: '.file_get_contents($dir.'/server.log'));sshCheck(true,'Native Unix forwarding accepted by real sshd');stream_set_timeout($receive,3);sshCheck(fgets($receive)==="native-test\n",'Transparent native forward request');fwrite($receive,"native-ok\n");sshCheck(fgets($send)==="native-ok\n",'Transparent native forward reply');fclose($receive);fclose($send);fclose($listener);$listener=null;
  sshCheck(str_contains(file_get_contents($dir.'/client.log'),'cipher: '.$cipher),'Selected GCM cipher negotiated');
  $tcp=unmLiveRunCommand(array_merge($base,['-c',$cipher,'-N','-o','ExitOnForwardFailure=yes','-R','127.0.0.1:0:127.0.0.1:'.$port,'root@127.0.0.1']),null,5);sshCheck($tcp['code']!==0&&str_contains($tcp['stderr'],'remote port forwarding failed'),'Ordinary remote listeners denied by actual key options');
  sshStop($client);if(file_exists($local))unlink($local);unlink($remote);
 }
 file_put_contents($dir.'/authorized_keys',unmLiveTrustedKeyLine($j,array_replace($key,['sourceAddress'=>'192.0.2.1']))."\n");
 $bad=unmLiveRunCommand(array_merge($base,['root@127.0.0.1','true']),null,5);sshCheck($bad['code']!==0&&str_contains($bad['stderr'],'Permission denied'),'Wrong authenticated source address rejected');
 file_put_contents($dir.'/authorized_keys',unmLiveTrustedKeyLine($j,array_replace($key,['expiresAt'=>time()-1]))."\n");
 $bad=unmLiveRunCommand(array_merge($base,['root@127.0.0.1','true']),null,5);sshCheck($bad['code']!==0&&str_contains($bad['stderr'],'Permission denied'),'Expired native key rejected');
 file_put_contents($dir.'/authorized_keys','');$bad=unmLiveRunCommand(array_merge($base,['root@127.0.0.1','true']),null,5);sshCheck($bad['code']!==0&&str_contains($bad['stderr'],'Permission denied'),'Removed native key rejected');
 echo "Real native SSH key/Unix forwarding/ciphers/remote-listener restriction/source/expiry/revocation checks passed ($checks).\n";
}finally{
 sshStop($client);if(is_resource($listener))fclose($listener);sshStop($server);
 foreach(glob($dir.'/*')?:[] as $p){if(is_link($p)||is_file($p)||filetype($p)==='socket')unlink($p);}rmdir($dir);
}
