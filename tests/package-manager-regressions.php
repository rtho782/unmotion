<?php
// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
declare(strict_types=1);
// Exercise the native plugin() FILE processor, not the manager's top-level CLI.
// All command execution and downloads are intercepted. Only random temporary
// fixture paths are written; no package installation, service or VM is touched.
[$script, $descriptor, $archive, $manager] = $argv;
function pmCheck(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
// Unraid omits PHP's tokenizer extension. Its named processor is a top-level
// function ending at a column-zero brace; do not load the surrounding CLI.
pmCheck(preg_match('/^function plugin\(\$method, \$plugin_file, &\$error\) \{\n.*?^\}/ms', file_get_contents($manager), $match) === 1, 'Native FILE processor found');
eval($match[0]);
$root = sys_get_temp_dir().'/unmotion-package-test-'.bin2hex(random_bytes(8));
mkdir($root, 0700);
$logger = 'unmotion-test'; $download_only = false; $check_version = '7.0.0';
$boot = $root.'/boot'; $nextboot = $root.'/nextboot';
$downloads = 0; $runs = []; $downloadMode = 'ok'; $runCode = 0; $failRunIndex=0;
function my_logger(...$args): void {}
function write(...$args): void {}
function filter_url($url): string { return (string)$url; }
function download($url, $name, &$error, $write = true): bool {
    global $archive, $downloads, $downloadMode;
    $downloads++;
    if ($downloadMode === 'offline') { $error = 'simulated unavailable asset'; return false; }
    if ($downloadMode === 'corrupt') return file_put_contents((string)$name, 'wrong archive') !== false;
    return copy($archive, (string)$name);
}
function run($command): int {
    global $runs, $runCode, $failRunIndex;
    $runs[] = (string)$command;
    if($failRunIndex>0&&count($runs)===$failRunIndex)return 1;
    return $runCode;
}
$text = str_replace('<!ENTITY plgdir "/boot/config/plugins/&name;">', '<!ENTITY plgdir "'.$root.'/boot/unmotion">', file_get_contents($descriptor), $count);
pmCheck($count === 1, 'Redirect package writes into temporary fixture');
$fixture = $root.'/test.plg';
file_put_contents($fixture, $text);
$xml = simplexml_load_string($text);
$cache = (string)$xml->FILE[1]['Name'];
pmCheck(str_starts_with($cache, $root.'/boot/'), 'Cache stays in fixture');
$error = '';
try {
    pmCheck(plugin('install', $fixture, $error) === true, 'Fresh installation: '.$error);
    pmCheck($downloads === 1 && count($runs) === 3, 'Guard first, download once, install then restart');
    pmCheck(str_starts_with($runs[0],'/usr/bin/php '), 'Preinstall guard precedes package replacement');
    pmCheck($runs[1] === '/sbin/upgradepkg --install-new '.$cache, 'Native upgrade command receives cache');
    pmCheck((fileperms($cache) & 0777) === 0600, 'Cache is private');
    $downloadMode = 'offline'; $runs = []; $downloads = 0;
    pmCheck(plugin('install', $fixture, $error) === true, 'Offline cached boot');
    pmCheck($downloads === 0 && count($runs) === 3, 'Valid cache never downloads');
    file_put_contents($cache, 'corrupt cache'); $downloadMode = 'ok'; $runs = [];
    pmCheck(plugin('install', $fixture, $error) === true && $downloads === 1, 'Corrupt cache redownloaded');
    pmCheck(hash_file('sha256', $cache) === hash_file('sha256', $archive), 'Cache restored exactly');
    unlink($cache); $downloadMode = 'corrupt'; $runs = [];
    pmCheck(plugin('install', $fixture, $error) === false && str_contains($error, 'SHA256'), 'Bad downloaded hash rejected');
    pmCheck(count($runs)===1 && !file_exists($cache), 'Only preinstall guard runs before bad hash blocks install/restart');
    $downloadMode = 'offline'; $runs = [];
    pmCheck(plugin('install', $fixture, $error) === false && count($runs)===1, 'Missing download blocks install and restart');
    $downloadMode = 'ok'; $runCode = 1; $runs = [];
    pmCheck(plugin('install', $fixture, $error) === false && count($runs) === 1, 'Preinstall guard failure prevents install and restart');
    $runCode=0;$failRunIndex=2;$runs=[];
    pmCheck(plugin('install', $fixture, $error) === false && count($runs)===2, 'Native package upgrade failure prevents restart');
    $failRunIndex=0;
    $runCode = 0; $runs = [];
    pmCheck(plugin('remove', $fixture, $error) === true && count($runs) === 1, 'Removal skips download and install');
    echo "Native Unraid FILE processor: fresh/cache/corruption/offline/upgrade-failure/removal checks passed (execution mocked).\n";
} finally {
    if (file_exists($cache)) unlink($cache);
    foreach ([dirname($cache), dirname(dirname($cache)), $boot] as $directory) if (is_dir($directory)) rmdir($directory);
    unlink($fixture); rmdir($root);
}
