<?php
// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
declare(strict_types=1);
require __DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/package-cache.php';
$root = sys_get_temp_dir().'/unmotion-cache-test-'.bin2hex(random_bytes(8));
$cache = $root.'/unmotion/packages';
mkdir($cache, 0700, true);
$descriptor = $root.'/unmotion.plg';
$currentName = 'unmotion-0.4.3_stable-noarch-1.txz';
$current = $cache.'/'.$currentName;
$messages = [];
$log = static function (string $message) use (&$messages): void { $messages[] = $message; };
$checks = 0;
function cacheCheck(bool $ok, string $message): void {
    global $checks;
    if (!$ok) throw new RuntimeException($message);
    $checks++;
}
function cacheBlocked(callable $operation, string $message): void {
    $blocked = false;
    try { $operation(); } catch (RuntimeException $error) { $blocked = true; }
    cacheCheck($blocked, $message);
}
function cacheDescriptor(string $root, string $name, string $hash): string {
    return '<!DOCTYPE PLUGIN [<!ENTITY name "unmotion"><!ENTITY root "'.$root.'/&name;">]>'
        .'<PLUGIN name="&name;"><FILE Name="&root;/packages/'.$name.'"><SHA256>'.$hash.'</SHA256></FILE></PLUGIN>';
}
try {
    file_put_contents($current, 'verified current package');
    $source = cacheDescriptor($root, $currentName, hash_file('sha256', $current));
    file_put_contents($descriptor, $source);
    $oldNames = ['unmotion-0.3.0_beta7-noarch-1.txz', 'unmotion-0.3.0_RC1-noarch-1.txz', 'unmotion-0.3.0_rc1-noarch-2.txz', 'unmotion-0.3.0_rc1-noarch-3.txz', 'unmotion-0.4.1-noarch-1.txz', 'unmotion-0.4.2_stable-noarch-1.txz', 'unmotion-0.4.3_beta10-noarch-1.txz', 'unmotion-0.4.3_rc1-noarch-1.txz'];
    foreach ($oldNames as $name) file_put_contents($cache.'/'.$name, 'superseded download');
    $retainedNames = ['unmotion-0.4.10_stable-noarch-1.txz', 'unmotion-0.4.4_beta1-noarch-1.txz', 'unmotion-0.4.3-noarch-1.txz', 'other-plugin-0.1-noarch-1.txz', 'notes.txt', 'unmotion-unrecognized-noarch-1.txz'];
    foreach ($retainedNames as $name) file_put_contents($cache.'/'.$name, 'retain');
    file_put_contents($root.'/outside.txz', 'outside package cache');
    symlink($root.'/outside.txz', $cache.'/unmotion-0.1.0-noarch-1.txz');
    mkdir($cache.'/unmotion-0.1.1-noarch-1.txz');
    link($root.'/outside.txz', $cache.'/unmotion-0.1.2-noarch-1.txz');
    file_put_contents($root.'/unmotion/settings.json', '{"keep":true}');
    mkdir($cache.'/nested'); file_put_contents($cache.'/nested/unmotion-0.1.3-noarch-1.txz', 'keep nested');
    $prune = static fn() => unmPrunePackageCache($root, '0.4.3', $log);
    cacheCheck($prune() === count($oldNames), 'Remove all recognized older downloads');
    foreach ($oldNames as $name) cacheCheck(!file_exists($cache.'/'.$name), 'Old package removed: '.$name);
    foreach ($retainedNames as $name) cacheCheck(file_exists($cache.'/'.$name), 'New/equal/unrelated package retained: '.$name);
    cacheCheck(file_exists($current) && hash_file('sha256', $current) === hash('sha256', 'verified current package'), 'Current offline-boot package preserved');
    cacheCheck(is_link($cache.'/unmotion-0.1.0-noarch-1.txz') && file_get_contents($root.'/outside.txz') === 'outside package cache', 'Symlink and external target retained');
    cacheCheck(is_dir($cache.'/unmotion-0.1.1-noarch-1.txz') && file_exists($cache.'/unmotion-0.1.2-noarch-1.txz'), 'Directory and hardlink retained');
    cacheCheck(file_get_contents($root.'/unmotion/settings.json') === '{"keep":true}' && file_exists($cache.'/nested/unmotion-0.1.3-noarch-1.txz'), 'Settings and nested files untouched');
    cacheCheck(count($messages) === count($oldNames) && str_contains($messages[0], 'GitHub Releases'), 'Removed downloads reported as recoverable');
    cacheCheck($prune() === 0, 'Repeated cleanup is idempotent');
    $old = $cache.'/unmotion-0.4.2_stable-noarch-1.txz';
    file_put_contents($old, 'old package');
    file_put_contents($current, 'corrupt');
    cacheBlocked($prune, 'Corrupt current package blocks cleanup');
    cacheCheck(file_exists($old), 'Old package survives corrupt current download');
    unlink($current);
    cacheBlocked($prune, 'Missing current package blocks cleanup');
    symlink($root.'/outside.txz', $current);
    cacheBlocked($prune, 'Symlink current package blocks cleanup');
    unlink($current); file_put_contents($current, 'verified current package');
    cacheBlocked(static fn() => unmPrunePackageCache($root, '0.4.4', $log), 'Runtime/descriptor mismatch blocks cleanup');
    file_put_contents($descriptor, cacheDescriptor($root, basename($old), hash_file('sha256', $old)));
    cacheBlocked($prune, 'Uncommitted upgrade preserves package in old registered descriptor');
    cacheCheck(file_exists($old), 'Previously registered package preserved');
    file_put_contents($descriptor, '<PLUGIN');
    cacheBlocked($prune, 'Invalid XML blocks cleanup');
    file_put_contents($descriptor, '<!DOCTYPE PLUGIN SYSTEM "file:///etc/passwd"><PLUGIN name="unmotion"/>');
    cacheBlocked($prune, 'External XML declarations are rejected');
    file_put_contents($descriptor, str_replace(hash_file('sha256', $current), '', $source));
    cacheBlocked($prune, 'Missing SHA-256 blocks cleanup');
    file_put_contents($descriptor, $source);
    symlink($cache, $root.'/redirected-cache');
    rename($cache, $root.'/real-cache');
    symlink($root.'/real-cache', $cache);
    cacheBlocked($prune, 'Redirected package cache blocks cleanup');
    unlink($cache); rename($root.'/real-cache', $cache); unlink($root.'/redirected-cache');
    $lock = fopen($cache.'/.cleanup.lock', 'c'); flock($lock, LOCK_EX);
    cacheBlocked($prune, 'Concurrent cleanup cannot run');
    flock($lock, LOCK_UN); fclose($lock);
    foreach ([
        ['hook', 'plugin', 'install', 'unmotion.plg', 'download failed'],
        ['hook', 'plugin', 'update', 'unmotion.plg', 'package install failed'],
        ['hook', 'plugin', 'update', 'unmotion.plg', 'service restart failed'],
        ['hook', 'plugin', 'update', 'unmotion.plg', '1'],
        ['hook', 'plugin', 'check', 'unmotion.plg', ''],
        ['hook', 'plugin', 'remove', 'unmotion.plg', ''],
        ['hook', 'plugin', 'download', 'unmotion.plg', ''],
        ['hook', 'plugin', 'install', 'other.plg', ''],
        ['hook', 'language', 'install', 'unmotion.plg', ''],
        ['hook', 'plugin', 'install'],
    ] as $args) {
        unmPackageCachePostHook($args, $root, '0.4.3', $log);
        cacheCheck(file_exists($old), 'Failure/non-install hook cannot prune: '.implode(' ', $args));
        $entry = __DIR__.'/../src/rootfs/usr/local/emhttp/plugins/dynamix.plugin.manager/post-hooks/unmotion-package-cache';
        $process = proc_open(array_merge([PHP_BINARY, $entry], array_slice($args, 1)), [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        cacheCheck(proc_close($process) === 0 && $output === '', 'Native entry ignores event without loading runtime files: '.implode(' ', $args));
    }
    unmPackageCachePostHook(['hook', 'plugin', 'update', 'unmotion.plg', ''], $root, '0.4.3', $log);
    cacheCheck(!file_exists($old), 'Successful registered upgrade runs cleanup');
    file_put_contents($old, 'old package');
    unmPackageCachePostHook(['hook', 'plugin', 'install', 'unmotion.plg', ''], $root, '0.4.3', $log);
    cacheCheck(!file_exists($old), 'Successful cached-boot install runs cleanup');
    file_put_contents($old, 'old package');
    unmPackageCachePostHook(['hook', 'plugin', 'update', 'unmotion.plg'], $root, '0.4.3', $log);
    cacheCheck(!file_exists($old), 'Native manager omits unquoted empty error on successful update');
    file_put_contents($old, 'old package');
    unmPackageCachePostHook(['hook', 'plugin', 'install', 'unmotion.plg'], $root, '0.4.3', $log);
    cacheCheck(!file_exists($old), 'Native manager omits unquoted empty error on successful install');
    file_put_contents($old, 'old package'); file_put_contents($descriptor, 'invalid');
    unmPackageCachePostHook(['hook', 'plugin', 'update', 'unmotion.plg', ''], $root, '0.4.3', $log);
    cacheCheck(file_exists($old) && str_contains(end($messages), 'cleanup skipped'), 'Cleanup failure is reported without failing the installed plugin');
    file_put_contents($descriptor, $source);
    file_put_contents($cache.'/unmotion-0.4.0-noarch-1.txz', 'old');
    cacheBlocked(static fn() => unmPrunePackageCache($root, '0.4.3', static function (string $message) use ($descriptor): void { file_put_contents($descriptor, 'changed'); }), 'Descriptor change during pruning stops remaining deletion');
    cacheCheck(file_exists($old), 'Remaining cached package protected after descriptor change');
    cacheCheck(version_compare(unmCachedPackageVersion('unmotion-0.4.3_beta9-noarch-1.txz'), unmCachedPackageVersion('unmotion-0.4.3_beta10-noarch-1.txz'), '<'), 'Numeric beta ordering');
    cacheCheck(unmCachedPackageVersion('../unmotion-0.4.2_stable-noarch-1.txz') === null, 'Path-like package name rejected');
    echo "Package-cache cleanup: $checks checks passed.\n";
} finally {
    // Delete only this test's randomly allocated tree; never follow fixture symlinks.
    $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($entries as $entry) { if ($entry->isDir() && !$entry->isLink()) rmdir($entry->getPathname()); else unlink($entry->getPathname()); }
    rmdir($root);
}
