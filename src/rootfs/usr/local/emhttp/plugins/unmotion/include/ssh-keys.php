<?php
// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
declare(strict_types=1);

function unmAssertPairIdentityAvailable(array $peers, string $host, int $port, string $hostId): void {
    foreach ($peers as $peer) {
        $sameAddress = strcasecmp((string)($peer['host']??''),$host) === 0 && (int)($peer['port']??22) === $port;
        $sameIdentity = $hostId !== '' && (string)($peer['hostId']??'') === $hostId;
        if ($sameAddress || $sameIdentity) throw new RuntimeException('A pairing already owns this address or host identity. Explicitly remove it before establishing another pairing; existing keys and settings were preserved.');
    }
}

function unmManagedSshKeyId(string $publicKey): string {
    $publicKey = unmValidatePublicKey($publicKey);
    $parts = preg_split('/\s+/', $publicKey, 3);
    $blob = base64_decode($parts[1], true);
    // An Ed25519 wire key contains a length-prefixed type and a 32-byte key.
    if ($blob === false || strlen($blob) !== 51 || substr($blob, 0, 15) !== pack('N', 11).'ssh-ed25519' || substr($blob, 15, 4) !== pack('N', 32)) {
        throw new InvalidArgumentException('Invalid Ed25519 wire key.');
    }
    return hash('sha256', $blob);
}

function unmManagedSshKeyLine(string $publicKey): string {
    $id = unmManagedSshKeyId($publicKey);
    $parts = preg_split('/\s+/', trim($publicKey), 3);
    return 'restrict,command="/usr/local/sbin/unmotion-ssh-gate '.$id.'" ssh-ed25519 '.$parts[1].' unmotion-managed:'.$id;
}

function unmAuthorizedKeyBlob(string $line): string {
    // Read only the key fields, never a comment or a quoted command option.
    $line = ltrim($line);
    if ($line === '' || $line[0] === '#') return '';
    $fields = []; $field = ''; $quoted = false; $escaped = false;
    for ($i = 0; $i < strlen($line); $i++) {
        $char = $line[$i];
        if ($escaped) { $field .= $char; $escaped = false; continue; }
        if ($char === '\\' && $quoted) { $escaped = true; $field .= $char; continue; }
        if ($char === '"') { $quoted = !$quoted; $field .= $char; continue; }
        if (!$quoted && ($char === ' ' || $char === "\t")) {
            if ($field !== '') { $fields[] = $field; $field = ''; }
            if (count($fields) === 3) break;
        } else $field .= $char;
    }
    if ($quoted || $escaped) return '';
    if ($field !== '' && count($fields) < 3) $fields[] = $field;
    $index = ($fields[0] ?? '') === 'ssh-ed25519' ? 0 : 1;
    if (($fields[$index] ?? '') !== 'ssh-ed25519') return '';
    $blob = $fields[$index + 1] ?? '';
    return preg_match('/^[A-Za-z0-9+\/=]+$/', $blob) ? $blob : '';
}

function unmRewriteManagedSshKeys(string $contents, array $publicKeys, string $mode): string {
    if (!in_array($mode, ['restrict', 'add', 'remove'], true)) throw new InvalidArgumentException('Invalid key rewrite mode.');
    $managed = [];
    foreach ($publicKeys as $key) {
        $line = unmManagedSshKeyLine((string)$key);
        $managed[unmAuthorizedKeyBlob($line)] = $line;
    }
    $lines = preg_split('/\r?\n/', $contents);
    if (end($lines) === '') array_pop($lines);
    $out = []; $seen = [];
    foreach ($lines as $line) {
        $blob = unmAuthorizedKeyBlob($line);
        if ($blob === '' || !isset($managed[$blob])) { $out[] = $line; continue; }
        if ($mode !== 'remove' && !isset($seen[$blob])) $out[] = $managed[$blob];
        $seen[$blob] = true;
    }
    if ($mode === 'add') foreach ($managed as $blob => $line) if (!isset($seen[$blob])) $out[] = $line;
    return $out ? implode("\n", $out)."\n" : '';
}

function unmUpdateManagedSshKeys(array $publicKeys, string $mode): void {
    if (!$publicKeys) return;
    $directory = '/root/.ssh';
    if (!is_dir($directory) && !mkdir($directory, 0700, true)) throw new RuntimeException('Unable to create SSH directory.');
    $path = $directory.'/authorized_keys';
    // Preserve Unraid's persistence symlink, if configured, rather than replace it.
    if (is_link($path)) {
        $resolved = realpath($path);
        if ($resolved === false || !in_array($resolved, ['/boot/config/ssh/root/authorized_keys', '/boot/config/ssh/authorized_keys'], true)) throw new RuntimeException('Unrecognised authorized_keys symlink; review SSH configuration before upgrading pairing.');
        $path = $resolved;
    }
    $lock = fopen($directory.'/.unmotion-keys.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Unable to lock SSH key update.');
    $tmp = null;
    try {
        $old = is_file($path) ? file_get_contents($path) : '';
        if ($old === false) throw new RuntimeException('Unable to read authorized_keys.');
        $new = unmRewriteManagedSshKeys($old, $publicKeys, $mode);
        if ($new === $old) return;
        $tmp = tempnam(dirname($path), '.unmotion-keys-');
        if ($tmp === false || !chmod($tmp, 0600) || file_put_contents($tmp, $new) !== strlen($new)) throw new RuntimeException('Unable to prepare restricted SSH keys.');
        if (!rename($tmp, $path)) throw new RuntimeException('Unable to replace authorized_keys.');
        $tmp = null;
    } finally {
        if ($tmp !== null && is_file($tmp)) unlink($tmp);
        flock($lock, LOCK_UN); fclose($lock);
    }
}

function unmRestrictExistingPeerKeys(): void {
    $keys = [];
    foreach (glob(UNM_PEERS_DIR.'/*.json') ?: [] as $path) {
        $peer = unmLoadJson($path);
        if (!empty($peer['incomingPublicKey'])) $keys[] = unmValidatePublicKey((string)$peer['incomingPublicKey']);
    }
    // Do not recreate intentionally revoked entries. All matching duplicates,
    // including an old unrestricted entry, collapse to one forced-command key.
    unmUpdateManagedSshKeys($keys, 'restrict');
}
