<?php
// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
declare(strict_types=1);

/** Only names emitted by the unMotion release builder are eligible for pruning. */
function unmCachedPackageVersion(string $name): ?string {
    if (!preg_match('/\Aunmotion-([0-9]+\.[0-9]+\.[0-9]+)(?:_((?i:stable|alpha[0-9]+|beta[0-9]+|rc[0-9]+)))?-noarch-[1-9][0-9]{0,5}\.txz\z/D', $name, $match)) return null;
    $suffix = strtolower($match[2] ?? 'stable');
    return $match[1].($suffix === 'stable' ? '' : '-'.$suffix);
}

function unmCacheRegularFile(string $path): array {
    clearstatcache(true, $path);
    $stat = @lstat($path);
    if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || $stat['nlink'] !== 1 || realpath($path) !== $path) {
        throw new RuntimeException('Not an isolated regular package-cache file: '.basename($path));
    }
    // Reading/hashing can update atime; it is not evidence that content changed.
    return array_intersect_key($stat, array_flip(['dev', 'ino', 'mode', 'nlink', 'size', 'mtime', 'ctime']));
}

/** Internal paths are supplied by the fixed native hook; tests use isolated fixtures. */
function unmPrunePackageCache(string $pluginRoot, string $runtimeVersion, callable $log): int {
    $cache = $pluginRoot.'/unmotion/packages';
    $descriptor = $pluginRoot.'/unmotion.plg';
    if (realpath($cache) !== $cache || !is_dir($cache)) throw new RuntimeException('Package cache is absent or redirected.');
    $lockPath = $cache.'/.cleanup.lock';
    if (is_link($lockPath) || (file_exists($lockPath) && !is_file($lockPath))) throw new RuntimeException('Package-cache lock is not a regular file.');
    $lock = @fopen($lockPath, 'c');
    if ($lock === false) throw new RuntimeException('Cannot open package-cache lock.');
    try {
        unmCacheRegularFile($lockPath);
        if (!flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('Another package-cache cleanup is active.');
        unmCacheRegularFile($descriptor);
        $source = file_get_contents($descriptor);
        if ($source === false || preg_match('/<!ENTITY\s+(?:%\s*)?\S+\s+(?:SYSTEM|PUBLIC)\b|<!DOCTYPE\s+\S+\s+(?:SYSTEM|PUBLIC)\b/i', $source)) {
            throw new RuntimeException('Cannot inspect the registered plugin descriptor.');
        }
        $previousErrors = libxml_use_internal_errors(true);
        try { $xml = simplexml_load_string($source, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($previousErrors); }
        if ($xml === false || $xml->getName() !== 'PLUGIN' || (string)$xml['name'] !== 'unmotion') throw new RuntimeException('Invalid registered plugin identity.');
        $packages = [];
        foreach ($xml->FILE as $file) {
            $path = (string)$file['Name'];
            if (dirname($path) === $cache && unmCachedPackageVersion(basename($path)) !== null) $packages[] = $file;
        }
        if (count($packages) !== 1) throw new RuntimeException('Registered descriptor does not identify exactly one known cached package.');
        $current = (string)$packages[0]['Name'];
        $currentVersion = unmCachedPackageVersion(basename($current));
        $expectedHash = (string)$packages[0]->SHA256;
        if (!preg_match('/\A[0-9a-f]{64}\z/D', $expectedHash) || version_compare($currentVersion, $runtimeVersion, '!=')) {
            throw new RuntimeException('Registered package does not match the running installation.');
        }
        $currentStat = unmCacheRegularFile($current);
        if (!hash_equals($expectedHash, (string)hash_file('sha256', $current)) || unmCacheRegularFile($current) !== $currentStat) {
            throw new RuntimeException('Current cached package could not be verified; all packages retained.');
        }
        $descriptorHash = hash('sha256', $source);
        $removed = 0;
        $entries = scandir($cache);
        if ($entries === false) throw new RuntimeException('Cannot list package cache.');
        foreach ($entries as $name) {
            $version = unmCachedPackageVersion($name);
            if ($version === null || !version_compare($version, $currentVersion, '<')) continue;
            $path = $cache.'/'.$name;
            try { $candidateStat = unmCacheRegularFile($path); }
            catch (RuntimeException $ignored) { continue; } // links/directories/unrelated objects are never removed
            // A failed registration or concurrent replacement must retain the old boot package.
            unmCacheRegularFile($descriptor);
            if (realpath($cache) !== $cache || hash_file('sha256', $descriptor) !== $descriptorHash || unmCacheRegularFile($current) !== $currentStat) {
                throw new RuntimeException('Installation changed during cleanup; remaining packages retained.');
            }
            if (unmCacheRegularFile($path) !== $candidateStat || !@unlink($path)) throw new RuntimeException('Could not remove cached package '.$name.'.');
            $removed++;
            $log('unMotion: removed superseded cached download '.$name.' (available again from GitHub Releases).');
        }
        return $removed;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function unmPackageCachePostHook(array $args, string $pluginRoot, string $runtimeVersion, callable $log): void {
    // Unraid calls post-hooks after descriptor registration, including on failure.
    // Do nothing for failed/download-only/check/remove/other-plugin operations.
    // Native Unraid interpolates the error without quoting: success omits it.
    if (!in_array(count($args), [4, 5], true) || $args[1] !== 'plugin' || !in_array($args[2], ['install', 'update'], true) || $args[3] !== 'unmotion.plg' || ($args[4] ?? '') !== '') return;
    try { unmPrunePackageCache($pluginRoot, $runtimeVersion, $log); }
    catch (Throwable $error) { $log('unMotion: package-cache cleanup skipped: '.$error->getMessage()); }
}
