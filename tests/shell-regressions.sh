#!/bin/bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SEED="$ROOT/src/rootfs/usr/local/sbin/unmotion-seed-worker"
WORKER="$ROOT/src/rootfs/usr/local/sbin/unmotion-worker"

row=$'zvol\tcache/Squid Proxy\t\tunmotion-seed-seed-test-g1\t\t'
IFS=$'\034' read -r kind src dst snap rest <<< "${row//$'\t'/$'\034'}"
[[ "$kind" == zvol ]]
[[ "$src" == 'cache/Squid Proxy' ]]
[[ -z "$dst" ]]
[[ "$snap" == 'unmotion-seed-seed-test-g1' ]]

grep -Fq 'IFS=$'"'"'\034'"'"' read -r kind src dst snap' "$SEED"
grep -Fq 'zfs get -H -o value receive_resume_token $(sq "$dst")' "$SEED"
! grep -F 'remote_resume_token(){' "$SEED" | grep -Fq '|| true'
[[ "$(grep -Fc 'elif zfs list -H -o name $(sq "$parent")' "$SEED")" == 1 ]]
[[ "$(grep -Fc 'elif zfs list -H -o name $(sq "$parent")' "$WORKER")" == 1 ]]
grep -Fq 'if ((WARM_MODE));then args+=(--inplace --no-whole-file);else args+=(--partial-dir=.unmotion-partial);fi' "$WORKER"
grep -Fq 'protect_warm_images(){' "$WORKER"
grep -Fq 'DEST_START_ATTEMPTED == 0' "$WORKER"
grep -Fq 'chmod 0400 $(sq "$dst")' "$WORKER"
[[ "$(grep -Fc 'protect_warm_images; restore_source' "$WORKER")" == 3 ]]

echo 'Shell RC1 behavior regressions passed.'
