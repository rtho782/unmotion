<?php
declare(strict_types=1);
require_once __DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/transport.php';
function unmTpmRoots():array{return ['/etc/libvirt/qemu/swtpm'];}
function unmTpmPathAllowed(string $p,string $uuid):bool{return $p==='/etc/libvirt/qemu/swtpm/'.$uuid;}
$dir=$argv[1]??'';
if(!preg_match('~\A/tmp/unmotion-transport-test\.[A-Za-z0-9]+\z~D',$dir)||!is_dir($dir))throw new RuntimeException('Use the isolated mktemp test directory.');
$dir.='/run-'.bin2hex(random_bytes(6));if(!mkdir($dir,0700))throw new RuntimeException('Unable to create isolated test run.');
$passed=0;
function check(bool $ok,string $label):void{global $passed;if(!$ok)throw new RuntimeException($label);$passed++;}
function bad(callable $fn,string $label):void{try{$fn();}catch(Throwable $e){check(true,$label);return;}throw new RuntimeException('Accepted: '.$label);}
function runTest(array $args):array{$p=proc_open($args,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);return [proc_close($p),$out,$err];}
$target=$dir.'/target';mkdir($target,0700);$name='Squid Proxy [1].raw';$filter=$dir.'/filter';$cfg=$dir.'/rsyncd.conf';
$runtimeFilter=unmTransportRuntimeDirectory().'/session-test/filters';
$runtimeConfig=unmTransportRsyncConfiguration($target,$runtimeFilter);
check(str_contains($runtimeConfig,$runtimeFilter)&&str_starts_with($runtimeFilter,'/run/'),'Native runtime rsync configuration accepted canonical /run path');
if(is_link('/var/run'))bad(fn()=>unmTransportRsyncConfiguration($target,'/var/run/unmotion/transport/session-test/filters'),'Runtime alias remains prohibited for peer-controlled paths');
file_put_contents($filter,'+ /'.strtr($name,['\\'=>'\\\\','['=>'\\[',']'=>'\\]'])."\n- /***\n");file_put_contents($cfg,unmTransportRsyncConfiguration($target,$filter));
$rsh=$dir.'/rsh';file_put_contents($rsh,"#!/bin/sh\nexec /usr/bin/rsync --server --daemon --config=".escapeshellarg($cfg)." .\n");chmod($rsh,0700);
$source=$dir.'/source.raw';$f=fopen($source,'w');fwrite($f,'begin');fseek($f,8388608);fwrite($f,'end');fclose($f);file_put_contents($target.'/do-not-touch','sentinel');
$base=['rsync','-rtS','--protect-args','--numeric-ids','--partial','--inplace','--no-whole-file','-e',$rsh,'--'];
[$code,$out,$err]=runTest(array_merge($base,[$source,'dummy::transfer/'.$name]));check($code===0,'Allowed rsync failed: '.$err.$out);check(hash_file('sha256',$source)===hash_file('sha256',$target.'/'.$name),'Rsync content mismatch');check(stat($target.'/'.$name)['blocks']*512<filesize($target.'/'.$name),'Sparse behavior lost');
[$code]=runTest(array_merge($base,[$source,'dummy::transfer/do-not-touch']));check($code!==0&&file_get_contents($target.'/do-not-touch')==='sentinel','Rsync overwrote excluded sibling');
[$code]=runTest(['rsync','-a','-e',$rsh,$source,'dummy::transfer/'.$name]);check($code!==0,'Rsync accepted unsafe archive options');
[$code]=runTest(['rsync','-r','--temp-dir=/','-e',$rsh,$source,'dummy::transfer/'.$name]);check($code!==0,'Rsync accepted arbitrary temp directory');
$link=$dir.'/link';symlink('/etc/shadow',$link);[$code]=runTest(['rsync','-rl','-e',$rsh,$link,'dummy::transfer/'.$name]);check($code!==0,'Rsync accepted symbolic links');
mkdir($dir.'/nested',0700);symlink('/etc',$dir.'/nested/parent');bad(fn()=>unmTransportPath($dir.'/nested/parent/shadow'),'Symlink ancestor rejected');
check(file_get_contents($target.'/do-not-touch')==='sentinel','Sibling damaged after negative tests');
$uuid='11111111-2222-4333-8444-555555555555';$path='etc/libvirt/qemu/swtpm/'.$uuid.'/tpm2-00.permall';$res=['vmUuid'=>$uuid,'hostStatePaths'=>['/etc/libvirt/qemu/swtpm/'.$uuid]];
function tarRecord(string $name,string $data,string $type='0',string $link=''):string{$h=str_pad($name,100,"\0").sprintf('%07o',0600)."\0".sprintf('%07o',0)."\0".sprintf('%07o',0)."\0".sprintf('%011o',strlen($data))."\0".sprintf('%011o',0)."\0".str_repeat(' ',8).$type.str_pad($link,100,"\0")."ustar\00000".str_repeat("\0",249);$h=substr($h,0,512);$sum=array_sum(unpack('C*',$h));$h=substr_replace($h,sprintf('%06o',$sum)."\0 ",148,8);return $h.$data.str_repeat("\0",(512-strlen($data)%512)%512);}
function writeArchive(string $file,array $entries):void{file_put_contents($file,gzencode(implode('',$entries).str_repeat("\0",1024)));}
$archive=$dir.'/archive.tar.gz';writeArchive($archive,[tarRecord($path,'TPM DATA')]);unmTransportArchive($archive,$res,false);check(true,'Safe TPM archive accepted');
foreach([['../../root/.ssh/authorized_keys','0',''],['root/.ssh/authorized_keys','0',''],[$path,'2','/root/.ssh/authorized_keys'],[$path,'1','root/.ssh/authorized_keys'],[$path,'3','']] as [$name,$type,$link]){writeArchive($archive,[tarRecord($name,'data',$type,$link)]);bad(fn()=>unmTransportArchive($archive,$res,false),'Malicious tar entry');}
writeArchive($archive,[tarRecord($path,'one'),tarRecord($path,'two')]);bad(fn()=>unmTransportArchive($archive,$res,false),'Duplicate tar');
$native='etc/libvirt/qemu/nvram/'.$uuid.'_VARS-pure-efi-tpm.fd';writeArchive($archive,[tarRecord($native,'UEFI')]);unmTransportArchive($archive,['vmUuid'=>$uuid,'replica'=>true],false);check(true,'Native Unraid NVRAM suffix accepted');
$fixture=$dir.'/gnu';mkdir($fixture.'/'.dirname($path),0700,true);file_put_contents($fixture.'/'.$path,'TPM DATA');
[$code,$out,$err]=runTest(['tar','--xattrs','--acls','--numeric-owner','-C',$fixture,'-czf',$archive,$path]);check($code===0,'GNU host-state tar generation failed: '.$err);unmTransportArchive($archive,$res,false);check(true,'GNU xattrs/ACL/PAX archive accepted');
echo "transport integration: $passed assertions passed\n";
