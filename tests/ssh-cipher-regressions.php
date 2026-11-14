<?php
// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
declare(strict_types=1);
require __DIR__.'/../src/rootfs/usr/local/emhttp/plugins/unmotion/include/ssh-cipher.php';
$checks=0;
function cipherOk(bool $ok,string $message):void{global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
cipherOk(unmSshCipher([])==='aes128-gcm@openssh.com','AES-128-GCM is the missing-setting default');
foreach(['aes128-gcm@openssh.com','aes256-gcm@openssh.com'] as $c)cipherOk(unmSshCipher(['ssh_cipher'=>$c])===$c,'Exact allowlisted cipher retained');
foreach(['none','aes128-ctr','chacha20-poly1305@openssh.com','aes128-gcm@openssh.com,aes256-gcm@openssh.com','aes128-gcm@openssh.com -o StrictHostKeyChecking=no',"aes128-gcm@openssh.com\n",'',false,[],null] as $bad){$denied=false;try{unmSshCipher(['ssh_cipher'=>$bad]);}catch(InvalidArgumentException){$denied=true;}cipherOk($denied,'Invalid setting rejected without fallback');}
$root=__DIR__.'/../src/rootfs';
foreach(['unmotion-worker','unmotion-seed-worker','unmotion-replication-worker'] as $name){$s=file_get_contents($root.'/usr/local/sbin/'.$name);cipherOk(str_contains($s,'include/ssh-cipher.sh'),'Worker validates cipher');cipherOk(str_contains($s,'SSH_OPTS=(-T -c "$SSH_CIPHER"'),'Worker SSH selects exactly one cipher');cipherOk(str_contains($s,'RSYNC_RSH="ssh -T -c $SSH_CIPHER'),'Worker rsync uses same cipher');cipherOk(str_contains($s,'log "SSH encryption: $SSH_CIPHER"'),'Worker records selected cipher');}
echo "SSH cipher regressions passed: $checks checks.\n";
