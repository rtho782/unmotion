#!/bin/bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
HELPER="$ROOT/src/rootfs/usr/local/emhttp/plugins/unmotion/include/ssh-cipher.sh"
test "$(unset SSH_CIPHER; source "$HELPER"; printf '%s' "$SSH_CIPHER")" = 'aes128-gcm@openssh.com'
for cipher in aes128-gcm@openssh.com aes256-gcm@openssh.com; do
  test "$(SSH_CIPHER="$cipher"; source "$HELPER"; printf '%s' "$SSH_CIPHER")" = "$cipher"
done
for bad in none aes128-ctr 'aes128-gcm@openssh.com,aes256-gcm@openssh.com' 'x; touch /tmp/not-authorised'; do
  if (SSH_CIPHER="$bad"; source "$HELPER") 2>/dev/null; then echo 'Unsafe cipher accepted' >&2; exit 1; fi
done
echo 'SSH shell cipher regressions passed.'
