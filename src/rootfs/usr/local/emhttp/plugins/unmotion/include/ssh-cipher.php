<?php
// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
declare(strict_types=1);

/** One explicit cipher: never silently fall back to another mode. */
function unmSshCipher(?array $config=null): string {
    $config??=unmLoadConfig();
    $cipher=array_key_exists('ssh_cipher',$config)?$config['ssh_cipher']:'aes128-gcm@openssh.com';
    if(!is_string($cipher)||!in_array($cipher,['aes128-gcm@openssh.com','aes256-gcm@openssh.com'],true))throw new InvalidArgumentException('Choose AES-128-GCM or AES-256-GCM for SSH encryption.');
    return $cipher;
}
