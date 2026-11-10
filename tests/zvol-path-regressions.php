<?php
// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
declare(strict_types=1);
require __DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/lib.php';
$checks=0;
function zcheck(bool $value,string $why): void {global $checks;if(!$value)throw new RuntimeException($why);$checks++;}
function zdeny(callable $fn,string $why): void {try{$fn();}catch(Throwable){zcheck(true,$why);return;}throw new RuntimeException($why);}
$dataset='cache/vm-zvols/Squid Proxy';$alias='/dev/zvol/cache/vm-zvols/Squid+Proxy';$kernel='cache/vm-zvols/Squid+Proxy';
zcheck(unmZvolDevicePath($dataset)===$alias,'OpenZFS encoding, not URL encoding');
zcheck(unmZvolInventoryMatch($alias,$kernel,[$dataset,'cache/other'])===$dataset,'unique actual dataset from kernel identity');
zcheck(unmZvolInventoryMatch('/dev/zvol/'.$dataset,$kernel,[$dataset])===$dataset,'canonical input still bound to kernel');
zdeny(fn()=>unmZvolInventoryMatch($alias,$kernel,[$dataset,$kernel]),'literal plus/space collision must not choose either');
zdeny(fn()=>unmZvolInventoryMatch($alias,$kernel,[]),'missing volume must not guess');
zdeny(fn()=>unmZvolInventoryMatch($alias,'cache/vm-zvols/Foreign',[$dataset]),'foreign kernel identity');
foreach(['cache/../foreign','cache/vm@point','cache/vm'."\t".'other','/cache/vm','cache//vm'] as $bad)zdeny(fn()=>unmZvolDevicePath($bad),'unsafe volume rejected');
zdeny(fn()=>unmZvolSourceDataset('/dev/sda'),'non-zvol device rejected');
zcheck(unmZvolSourceDataset('/dev/zvol/cache/vm-zvols/Plain')==='cache/vm-zvols/Plain','unencoded legacy identity preserved');
$maps=['/dev/zvol/'.$dataset=>'/dev/zvol/other/target'];zcheck(unmZvolMappingKey($alias,$maps)==='/dev/zvol/'.$dataset,'encoded XML mapped by exact admitted object');
zdeny(fn()=>unmZvolMappingKey($alias,$maps+[$alias=>'/dev/zvol/other/foreign']),'ambiguous mapping refused');
$vm=['disks'=>[['type'=>'zvol','source'=>$alias]]];zdeny(fn()=>unmZvolAssertPeer($vm,[]),'mixed peers reject affected paths before transfer');unmZvolAssertPeer($vm,['zvolPathVersion'=>1]);zcheck(true,'advertised support');unmZvolAssertPeer(['disks'=>[['type'=>'zvol','source'=>'/dev/zvol/cache/plain']]],[]);zcheck(true,'ordinary protocol7 remains compatible');
$dir=sys_get_temp_dir().'/unmotion-zvol-path-'.bin2hex(random_bytes(8));mkdir($dir);
try{
    $xml='<domain><name>Squid Proxy</name><description>'.$alias.'</description><devices><disk type="block" device="disk"><driver type="raw"/><source dev="'.$alias.'"/><target dev="vda"/></disk></devices></domain>';
    file_put_contents($dir.'/source.xml',$xml);$transform=__DIR__.'/../src/rootfs/usr/local/sbin/unmotion-transform';
    foreach(['disk','zvol-file'] as $type){
        $target=$type==='disk'?'/dev/zvol/other/Squid Proxy':'/mnt/other/Squid Proxy/disk.img';
        file_put_contents($dir.'/map.tsv',$type."\t/dev/zvol/".$dataset."\t".$target."\n");$r=unmRun([PHP_BINARY,$transform,$dir.'/source.xml',$dir.'/dest.xml',$dir.'/map.tsv','retain','0'],null,10);zcheck($r['code']===0,'transform succeeds: '.$r['stderr']);
        $doc=new DOMDocument();$doc->loadXML((string)file_get_contents($dir.'/dest.xml'));$xp=new DOMXPath($doc);
        zcheck($xp->evaluate('string(/domain/description)')===$alias,'no global replacement of unrelated text');
        zcheck($xp->evaluate('string(/domain/devices/disk/source/@'.($type==='disk'?'dev':'file').')')===($type==='disk'?unmZvolDevicePath(substr($target,10)):$target),'exact alias transformed to correct target');
        zcheck($xp->evaluate('string(/domain/devices/disk/@type)')===($type==='disk'?'block':'file'),'correct block/file type');
    }
    file_put_contents($dir.'/source.xml',str_replace('</devices>','<disk type="block" device="disk"><source dev="'.$alias.'"/></disk></devices>',$xml));
    $r=unmRun([PHP_BINARY,$transform,$dir.'/source.xml',$dir.'/dest.xml',$dir.'/map.tsv','retain','0'],null,10);zcheck($r['code']!==0,'duplicate XML source refused');
}finally{foreach(['source.xml','dest.xml','map.tsv'] as $file)if(is_file($dir.'/'.$file))unlink($dir.'/'.$file);rmdir($dir);}
echo "Encoded zvol identity and mapping checks passed: $checks.\n";
