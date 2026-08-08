#!/bin/bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
LIB="$ROOT/src/rootfs/usr/local/emhttp/plugins/unmotion/include/lib.php"
RC="$ROOT/src/rootfs/etc/rc.d/rc.unmotion"
SEED="$ROOT/src/rootfs/usr/local/sbin/unmotion-seed-worker"
WORKER="$ROOT/src/rootfs/usr/local/sbin/unmotion-worker"

grep -Eq 'const[[:space:]]+UNM_PROTOCOL[[:space:]]*=[[:space:]]*5;' "$LIB"
grep -q '<txt-record>protocol=5</txt-record>' "$RC"
grep -q 'receive_resume_token' "$SEED"
grep -q 'zfs send -t' "$SEED"
grep -q 'DEST_DEDUP_REMOTE' "$SEED"
grep -q 'Transfer plan:' "$WORKER"
grep -q 'bytes=' "$WORKER"
echo 'Static beta7 regression markers are present.'
