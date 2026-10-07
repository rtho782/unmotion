<?php
// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
declare(strict_types=1);
// Before publishing: php scripts/check-release-feed.php candidate.plg current-feed.plg
function unmFeedVersion(string $path): array {
    $xml = simplexml_load_file($path, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
    if ($xml === false || (string)$xml['name'] !== 'unmotion') throw new RuntimeException('Invalid unMotion feed descriptor.');
    $version = (string)$xml['version'];
    if (preg_match('/\A([0-9]{4}\.[0-9]{2}\.[0-9]{2}\.[0-9]{2})-([0-9]+\.[0-9]+\.[0-9]+(?:-[A-Za-z0-9.]+)?)\z/D', $version, $m)) return [$version, $m[2], $m[1]];
    if (preg_match('/\A([0-9]+\.[0-9]+\.[0-9]+)(?:-(stable|[A-Za-z0-9.]+))?\z/D', $version, $m)) return [$version, $m[1].(isset($m[2]) && $m[2] !== 'stable' ? '-'.$m[2] : ''), ''];
    throw new RuntimeException('Unrecognized feed version.');
}
function unmAssertFeedAdvance(string $candidate, string $current): void {
    [$next, $nextApp, $nextId] = unmFeedVersion($candidate);
    [$old, $oldApp, $oldId] = unmFeedVersion($current);
    if (hash_file('sha256', $candidate) === hash_file('sha256', $current)) return;
    if ($nextId === '' || strcmp($next, $old) <= 0 || ($oldId !== '' && strcmp($nextId, $oldId) <= 0) || version_compare($nextApp, $oldApp, '<=')) {
        throw new RuntimeException('Feed must advance both installer ID and application version; no same-version replacement or cross-channel downgrade.');
    }
}
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        if ($argc !== 3) throw new RuntimeException('Usage: check-release-feed.php candidate.plg current-feed.plg');
        unmAssertFeedAdvance($argv[1], $argv[2]);
        echo "Feed ordering and application upgrade verified.\n";
    } catch (Throwable $error) { fwrite(STDERR, $error->getMessage()."\n"); exit(1); }
}
