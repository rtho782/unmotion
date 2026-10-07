<?php
// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
declare(strict_types=1);
require_once __DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/lib.php';

function keyCheck(bool $condition,string $label): void {
    if (!$condition) throw new RuntimeException($label);
}
function keyReject(callable $operation,string $label): void {
    try {$operation();} catch (InvalidArgumentException|RuntimeException $expected) {return;}
    throw new RuntimeException('Accepted invalid key: '.$label);
}
function keyFixture(string $bytes): string {
    return base64_encode(pack('N',11).'ssh-ed25519'.pack('N',strlen($bytes)).$bytes);
}

// Public wire fixtures only: no real private keys or authorized_keys files.
$blob=keyFixture(str_repeat('a',32));$otherBlob=keyFixture(str_repeat('b',32));
$public='ssh-ed25519 '.$blob.' unmotion-peer';
$expectedId=hash('sha256',(string)base64_decode($blob,true));
keyCheck(unmManagedSshKeyId($public)===$expectedId,'Managed identity binds complete Ed25519 wire key');
$restricted='restrict,command="/usr/local/sbin/unmotion-ssh-gate '.$expectedId.'" ssh-ed25519 '.$blob.' unmotion-managed:'.$expectedId;
keyCheck(unmManagedSshKeyLine($public)===$restricted,'Managed key receives restrict and exact fixed forced command');
keyCheck(unmManagedSshKeyLine('ssh-ed25519 '.$blob.' another comment')===$restricted,'Comments do not change managed key identity');
keyCheck(unmAuthorizedKeyBlob($restricted)===$blob,'Restricted entry parser finds the actual key');
foreach([
    'ssh-rsa '.$blob,
    'ssh-ed25519 !!invalid!!',
    'ssh-ed25519 '.base64_encode('ssh-ed25519'),
    'ssh-ed25519 '.keyFixture(str_repeat('a',31)),
    'ssh-ed25519 '.keyFixture(str_repeat('a',33)),
    'ssh-ed25519 '.base64_encode(pack('N',10).'ssh-ed25519'.pack('N',32).str_repeat('a',32)),
    'ssh-ed25519 '.base64_encode(pack('N',11).'ssh-ed25518'.pack('N',32).str_repeat('a',32)),
    'ssh-ed25519 '.base64_encode(pack('N',11).'ssh-ed25519'.pack('N',31).str_repeat('a',32)),
    'ssh-ed25519 '.base64_encode((string)base64_decode($blob,true).'trailing'),
    'command="sh" '.$public,
    $public."\nssh-ed25519 ".$otherBlob,
] as $bad) keyReject(fn()=>unmManagedSshKeyLine($bad),$bad);

$comment='# ssh-ed25519 '.$blob.' appears in a comment only';
$quoted='command="echo ssh-ed25519 '.$blob.'",no-port-forwarding ssh-ed25519 '.$otherBlob.' administrator with quoted spaces';
$escaped='command="echo \\"ssh-ed25519 '.$blob.'\\"",restrict ssh-ed25519 '.$otherBlob.' administrator with escaped quotes';
$admin='from="192.0.2.1",no-agent-forwarding ssh-ed25519 '.$otherBlob.' personal-admin';
$rsa='command="backup --safe",restrict ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAABAQfixture another-admin';
keyCheck(unmAuthorizedKeyBlob($comment)==='','Comments cannot impersonate a key');
keyCheck(unmAuthorizedKeyBlob($quoted)===$otherBlob,'A quoted command cannot impersonate the managed key');
keyCheck(unmAuthorizedKeyBlob($escaped)===$otherBlob,'Escaped quotes do not confuse key parsing');
$unrelated=implode("\n",[$comment,$quoted,$escaped,$admin,$rsa,''])."\n";
$existing=$unrelated.$public."\n".'from="192.0.2.2",command="old wrapper" ssh-ed25519 '.$blob.' old duplicate'."\n".$restricted."\n";
$updated=unmRewriteManagedSshKeys($existing,[$public],'restrict');
keyCheck($updated===$unrelated.$restricted."\n",'All managed duplicates collapse to one restricted line; unrelated comments/options are preserved');
keyCheck(unmRewriteManagedSshKeys($updated,[$public],'restrict')===$updated,'Restriction is idempotent');
keyCheck(unmRewriteManagedSshKeys($unrelated,[$public],'restrict')===$unrelated,'Upgrade does not restore an intentionally revoked/missing managed key');
keyCheck(unmRewriteManagedSshKeys('',[$public],'restrict')==='','An empty authorized_keys is not repopulated during upgrade');
keyCheck(unmRewriteManagedSshKeys($existing,[$public],'remove')===$unrelated,'Unpair removes every managed variant but preserves administrator keys');
keyCheck(unmRewriteManagedSshKeys($unrelated,[$public],'add')===$unrelated.$restricted."\n",'Explicit pairing can add a previously absent restricted key');
keyCheck(unmRewriteManagedSshKeys($updated,[$public],'add')===$updated,'Pairing cannot duplicate an already restricted key');
keyReject(fn()=>unmRewriteManagedSshKeys($existing,[$public],'unknown'),'unknown rewrite mode');

$peers=[['host'=>'dev-one','port'=>22,'hostId'=>'existing-identity','incomingPublicKey'=>$otherBlob,'pairingState'=>'paired']];
keyReject(fn()=>unmAssertPairIdentityAvailable($peers,'DEV-ONE',22,'new-identity'),'pending key cannot replace an established address');
keyReject(fn()=>unmAssertPairIdentityAvailable($peers,'different-name',2222,'existing-identity'),'host identity cannot be claimed through another address');
unmAssertPairIdentityAvailable($peers,'new-host',22,'new-identity');
echo "SSH key wire validation, admin/comment preservation, duplicate restriction and revocation regressions passed.\n";
