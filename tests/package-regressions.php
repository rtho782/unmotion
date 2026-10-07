<?php
// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
declare(strict_types=1);
function packageCheck(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
[$script, $descriptor, $package, $version, $releaseId] = $argv;
$source = file_get_contents($descriptor);
$xml = simplexml_load_string($source, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
packageCheck($xml !== false, 'Generated XML must parse');
$pluginVersion = $releaseId.'-'.$version;
$packageVersion = str_contains($version, '-') ? $version : $version.'-stable';
$channel = str_contains($version, '-') ? 'beta' : 'stable';
$packageName = 'unmotion-'.strtolower(str_replace('-', '_', $packageVersion)).'-noarch-1.txz';
packageCheck((string)$xml['name'] === 'unmotion', 'Stable plugin identity');
packageCheck((string)$xml['author'] === 'Richard Skinner', 'Exact author');
packageCheck((string)$xml['version'] === $pluginVersion, 'Upgrade version ordering');
packageCheck((string)$xml['pluginURL'] === "https://raw.githubusercontent.com/rtho782/unmotion/plugin-$channel/unmotion.plg", 'Correct update channel');
packageCheck((string)$xml['min'] === '7.3.2', 'Minimum Unraid matches the supported development baseline');
packageCheck(count($xml->FILE) === 4, 'Only preinstall guard, download, restart and removal FILE elements');
$guard=$xml->FILE[0];
packageCheck((string)$guard['Run']==='/usr/bin/php','Upgrade guard uses PHP before upgradepkg');
$guardSource=(string)$guard->INLINE;
packageCheck(str_contains($guardSource,'unmProcessPreinstall();')&&str_contains($guardSource,'function unmProcessSnapshot('),'Readable self-contained upgrade guard');
$guardProcess=proc_open([PHP_BINARY,'-l'],[['pipe','r'],['pipe','w'],['pipe','w']],$guardPipes);
fwrite($guardPipes[0],$guardSource);fclose($guardPipes[0]);
$guardOutput=stream_get_contents($guardPipes[1]).stream_get_contents($guardPipes[2]);fclose($guardPipes[1]);fclose($guardPipes[2]);
packageCheck(proc_close($guardProcess)===0,'Valid preinstall PHP: '.$guardOutput);
$download = $xml->FILE[1];
packageCheck((string)$download['Name'] === '/boot/config/plugins/unmotion/packages/'.$packageName, 'Persistent package cache');
packageCheck((string)$download['Run'] === '/sbin/upgradepkg --install-new', 'Native package install');
packageCheck((string)$download['Mode'] === '0600', 'Package mode preserved');
packageCheck((string)$download->URL === "https://github.com/rtho782/unmotion/releases/download/$version/$packageName", 'Tagged HTTPS asset, not latest or moving branch');
packageCheck((string)$download->SHA256 === hash_file('sha256', $package), 'SHA-256 matches exact archive');
packageCheck(!isset($download->INLINE) && !isset($download->MD5), 'No embedded payload or MD5-only integrity');
packageCheck(!preg_match('/base64|ENTITY payload|Embedded package|\.txz\.b64/', $source), 'No hidden archive/decode or obsolete payload references');
packageCheck(trim((string)$xml->FILE[2]->INLINE) === '/etc/rc.d/rc.unmotion restart || exit 1', 'Restart failure is reported after package installation');
packageCheck((string)$xml->FILE[3]['Method'] === 'remove', 'Cleanup runs only on uninstall');
packageCheck(str_contains((string)$xml->FILE[3]->INLINE,'/etc/rc.d/rc.unmotion stop || exit 1')&&str_contains((string)$xml->FILE[3]->INLINE,'/usr/local/sbin/unmotion-cleanup || exit 1'),'Unsafe stop/cleanup aborts removal');
$removeSource=(string)$xml->FILE[3]->INLINE;
$fenceCheck=strpos($removeSource,'/usr/local/sbin/unmotion-native-fence check-remove || exit 1');
packageCheck($fenceCheck!==false&&$fenceCheck<strpos($removeSource,'/etc/rc.d/rc.unmotion stop'),'Native fencing removal gate must precede service stop');
foreach ([2, 3] as $index) {
    packageCheck((string)$xml->FILE[$index]['Run'] === '/bin/bash', 'Shell action interpreter');
    $pipes = [];
    $process = proc_open(['bash', '-n'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    fwrite($pipes[0], (string)$xml->FILE[$index]->INLINE);
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    packageCheck(proc_close($process) === 0, 'Valid embedded shell: '.$output);
}

// libvirt discovers executable qemu.d files: shipping the hook as ordinary
// documentation would silently remove pre-start enforcement after install.
$nativeContents=[];
foreach([
    './etc/libvirt/hooks/qemu.d/50-unmotion-recovery'=>'-rwxr-xr-x',
    './usr/local/sbin/unmotion-native-fence'=>'-rwxr-xr-x',
    './usr/local/emhttp/plugins/unmotion/include/native-fence.php'=>'-rw-r--r--',
    './usr/local/emhttp/plugins/unmotion/include/native-qemu-hook'=>'-rw-r--r--',
    './usr/local/emhttp/plugins/dynamix.plugin.manager/post-hooks/unmotion-package-cache'=>'-rwxr-xr-x',
    './usr/local/emhttp/plugins/unmotion/include/package-cache.php'=>'-rw-r--r--',
] as $entry=>$mode){
    $pipes=[];$process=proc_open(['tar','-tvf',$package,$entry],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);
    fclose($pipes[0]);$listing=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    packageCheck(proc_close($process)===0,'Native hook dependency packaged: '.$entry.' '.$error);
    packageCheck(str_starts_with($listing,$mode.' '),'Native hook dependency mode: '.$entry);
    $pipes=[];$process=proc_open(['tar','-xOf',$package,$entry],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);
    fclose($pipes[0]);$contents=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    packageCheck(proc_close($process)===0&&$contents!=='','Native hook dependency is readable: '.$entry.' '.$error);
    $nativeContents[$entry]=$contents;
    $pipes=[];$process=proc_open([PHP_BINARY,'-l'],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);
    fwrite($pipes[0],$contents);fclose($pipes[0]);$output=stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    packageCheck(proc_close($process)===0,'Native hook dependency PHP syntax: '.$entry.' '.$output);
}
packageCheck($nativeContents['./etc/libvirt/hooks/qemu.d/50-unmotion-recovery']===$nativeContents['./usr/local/emhttp/plugins/unmotion/include/native-qemu-hook'],'Reinstallation template exactly matches the executable native hook');
echo "Generated $version descriptor identity, feed, SHA-256 and shell checks passed.\n";
