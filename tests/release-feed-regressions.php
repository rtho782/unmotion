<?php
// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
declare(strict_types=1);
require __DIR__.'/../scripts/check-release-feed.php';
$root = sys_get_temp_dir().'/unmotion-feed-test-'.bin2hex(random_bytes(8)); mkdir($root, 0700);
$cases = [
    ['0.4.2-stable', '2026.10.04.01-0.4.3', true],
    ['0.4.2-beta1', '2026.10.04.01-0.4.3', true],
    ['2026.10.04.01-0.4.3-beta9', '2026.10.04.02-0.4.3-beta10', true],
    ['2026.10.04.02-0.4.3-beta10', '2026.10.04.03-0.4.3', true],
    ['2026.10.04.01-0.4.9', '2026.10.04.02-0.4.10', true],
    ['2026.10.04.01-0.9.0', '2026.10.04.02-0.10.0', true],
    ['2026.10.04.01-0.4.3', '2026.10.04.01-0.4.4', false],
    ['2026.10.04.02-0.4.3', '2026.10.04.01-0.4.4', false],
    ['2026.10.04.01-0.5.0-beta1', '2026.10.04.02-0.4.3', false],
    ['2026.10.04.01-0.4.3', '2026.10.04.02-0.4.3', false],
];
try {
    foreach ($cases as [$old, $new, $expected]) {
        file_put_contents($root.'/old.plg', '<PLUGIN name="unmotion" version="'.$old.'"/>');
        file_put_contents($root.'/new.plg', '<PLUGIN name="unmotion" version="'.$new.'"/>');
        $allowed = true;
        try { unmAssertFeedAdvance($root.'/new.plg', $root.'/old.plg'); } catch (RuntimeException $error) { $allowed = false; }
        if ($allowed !== $expected) throw new RuntimeException("Incorrect feed admission: $old -> $new");
    }
    unmAssertFeedAdvance($root.'/old.plg', $root.'/old.plg');
    echo "Release feed advancement: 11 checks passed.\n";
} finally { unlink($root.'/old.plg'); unlink($root.'/new.plg'); rmdir($root); }
