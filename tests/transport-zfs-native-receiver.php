<?php
// Opt-in disposable-host test helper; never installed in the plugin package.
require $argv[1];
$kind=$argv[2];$target=$argv[3];
if(!preg_match('~\A(?:cache|zfspool)/unmotion-beta042-transport-[A-Za-z0-9]+/[a-z-]+\z~D',$target))throw new RuntimeException('Not a disposable transport probe target.');
$cmd=['zfs','receive','-su','-o','readonly=on','-o','dedup=off','-o','compression=lz4'];
$props=$kind==='dataset'?['canmount=off','mountpoint=none','setuid=off','devices=off','exec=off','sharenfs=off','sharesmb=off']:['volmode=none','snapdev=hidden'];
foreach($props as $prop){$cmd[]='-o';$cmd[]=$prop;}$cmd[]=$target;
try{$result=unmTransportReceiveZfs($cmd,$kind);exit($result['code']);}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(126);}
