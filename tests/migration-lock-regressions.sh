#!/bin/bash
# SPDX-License-Identifier: GPL-3.0-only
# Copyright (C) 2026 Richard Skinner
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
source "$ROOT/src/rootfs/usr/local/emhttp/plugins/unmotion/include/migration-lock.sh"
task_dir=$(mktemp -d)
trap 'rm -f -- "$task_dir/lock" "$task_dir/waiting" "$task_dir/cancel" "$task_dir/result"; rmdir "$task_dir"' EXIT
unm_migration_wait_cancelled(){ [[ -f "$task_dir/cancel" ]]; }
unm_migration_wait_status(){ echo waiting >>"$task_dir/waiting"; }
exec 9>"$task_dir/lock"
unm_wait_migration_lock 9 shared
flock -sn "$task_dir/lock" true
if flock -xn "$task_dir/lock" true; then echo 'Exclusive job overlapped shared cutovers' >&2;exit 1;fi
flock -u 9
flock -xn 9
(
 exec 9>&-
 exec 8>"$task_dir/lock"
 if unm_wait_migration_lock 8 shared; then echo 0 >"$task_dir/result";else echo "$?" >"$task_dir/result";fi
) & child=$!
for ((i=0;i<50;i++));do [[ -f "$task_dir/waiting" ]]&&break;sleep .1;done
test -f "$task_dir/waiting";test ! -f "$task_dir/result"
sleep 2;test "$(wc -l <"$task_dir/waiting")" = 1
touch "$task_dir/cancel";wait "$child";test "$(cat "$task_dir/result")" = 130
rm -f -- "$task_dir/cancel" "$task_dir/waiting" "$task_dir/result"
(
 exec 9>&-
 exec 8>"$task_dir/lock"
 unm_wait_migration_lock 8 shared
 echo acquired >"$task_dir/result"
) & child=$!
for ((i=0;i<50;i++));do [[ -f "$task_dir/waiting" ]]&&break;sleep .1;done
test ! -f "$task_dir/result";flock -u 9;wait "$child";test "$(cat "$task_dir/result")" = acquired
exec 9>&-
echo 'Parallel warm/shared lock, exclusive contention queue, cancellation and wake-up regressions passed.'
