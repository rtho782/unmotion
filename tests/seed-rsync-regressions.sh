#!/bin/bash
# SPDX-License-Identifier: GPL-3.0-only
# Copyright (C) 2026 Richard Skinner
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
source "$ROOT/src/rootfs/usr/local/emhttp/plugins/unmotion/include/numeric.sh"
source "$ROOT/src/rootfs/usr/local/emhttp/plugins/unmotion/include/seed-rsync.sh"
task_dir=$(mktemp -d /tmp/unmotion-seed-rsync.XXXXXX)
trap 'case "$task_dir" in /tmp/unmotion-seed-rsync.*) rm -rf -- "$task_dir" ;; esac' EXIT
checks=0
ok(){ ((checks+=1)); }
reject(){ if "$@" >"$task_dir/rejected.stdout" 2>"$task_dir/rejected.stderr"; then echo "Unexpected acceptance: $*" >&2; exit 1; fi; ok; }
report="$task_dir/stats"
printf 'Number of files: 1 (reg: 1)\nTotal file size: 1234 bytes\n' > "$report"
[[ "$(unm_seed_rsync_size "$report")" == 1234 ]]; ok
unm_seed_rsync_verify 1234 1234 1234 2345 online; ok
unm_seed_rsync_verify 1234 1234 1234 1000 online; ok
unm_seed_rsync_verify 1234 1234 1234 1234 offline; ok
unm_seed_rsync_verify 0 0 0 0 offline; ok
reject unm_seed_rsync_verify 1234 1233 1234 2345 online
reject unm_seed_rsync_verify 1234 0 1234 1234 online
reject unm_seed_rsync_verify 1234 1234 1234 2345 offline
reject unm_seed_rsync_verify 1234 1234 1200 1234 offline
reject unm_seed_rsync_verify 1234 invalid 1234 2345 online
reject unm_seed_rsync_verify 1234 1234 1234 2345 unknown
for bad in 'Total file size: 1,234 bytes' 'Total file size: 1.2K bytes' 'Total file size: 9223372036854775808 bytes' 'Number of files: 2 (reg: 2)' 'Total file size: 0 bytes'; do
  printf '%s\n' "$bad" > "$report"; reject unm_seed_rsync_size "$report"
done
printf 'Number of files: 1 (reg: 1)\nTotal file size: 1234 bytes\nTotal file size: 1234 bytes\n' > "$report"; reject unm_seed_rsync_size "$report"
command -v rsync >/dev/null || { echo 'rsync is required for growing-file regressions' >&2; exit 1; }
mkdir "$task_dir/Squid Proxy"
src="$task_dir/Squid Proxy/source.raw"; dst="$task_dir/Squid Proxy/seed.raw"
truncate -s 16777216 "$src"
LC_ALL=C rsync -rtS --inplace --no-whole-file --stats --no-human-readable --bwlimit=4096 -- "$src" "$dst" > "$task_dir/real-stats" 2>&1 &
rsync_pid=$!
for _attempt in {1..200}; do [[ -f "$dst" && $(stat -c %s "$dst") -gt 0 ]] && break; kill -0 "$rsync_pid" 2>/dev/null || break; sleep 0.05; done
[[ -f "$dst" && $(stat -c %s "$dst") -gt 0 ]]; ok
truncate -s 20971520 "$src"
wait "$rsync_pid"
sed -n '/^Number of files:/p;/^Total file size:/p' "$task_dir/real-stats" > "$report"
sent=$(unm_seed_rsync_size "$report"); received=$(stat -c %s "$dst"); after=$(stat -c %s "$src")
[[ "$sent" == 16777216 && "$received" == "$sent" && "$after" == 20971520 ]]; ok
unm_seed_rsync_verify "$sent" "$received" 16777216 "$after" online; ok
reject unm_seed_rsync_verify "$sent" "$received" 16777216 "$after" offline
# The unchanged quick-check case still supplies a single-file total, not zero.
LC_ALL=C rsync -rtS --stats --no-human-readable -- "$src" "$dst" > "$task_dir/real-stats"
LC_ALL=C rsync -rtS --stats --no-human-readable -- "$src" "$dst" > "$task_dir/real-stats"
sed -n '/^Number of files:/p;/^Total file size:/p' "$task_dir/real-stats" > "$report"
[[ "$(unm_seed_rsync_size "$report")" == 20971520 ]]; ok

# Execute both shipped progress parsers: rsync has a fourth side-channel
# argument, while the unchanged ZFS parser must still accept only three.
log(){ printf '%s\n' "$*" >> "$LOG"; }
test_seed_state(){ :; }
LOG="$task_dir/progress.log"
# Used by the extracted shipped parser below.
# shellcheck disable=SC2034
SEED_ID=seed-test
eval "$(sed -n '/^rsync_seed_progress_parser(){/,/^}/p' "$ROOT/src/rootfs/usr/local/sbin/unmotion-seed-worker" | sed 's|/usr/local/sbin/unmotion-seed-state|test_seed_state|g')"
: > "$report"
rsync_seed_progress_parser 15 65 'Squid Proxy' "$report" < "$task_dir/real-stats" > "$task_dir/parser.stdout"
[[ "$(unm_seed_rsync_size "$report")" == 20971520 && ! -s "$task_dir/parser.stdout" ]]; ok
eval "$(sed -n '/^seed_progress_parser(){/,/^}/p' "$ROOT/src/rootfs/usr/local/sbin/unmotion-seed-worker" | sed 's|/usr/local/sbin/unmotion-seed-state|test_seed_state|g')"
printf '50\n100\n' | seed_progress_parser 15 65 'ZFS Squid Proxy' > "$task_dir/parser.stdout"
[[ ! -s "$task_dir/parser.stdout" ]]; ok

# Exercise the shipped final-transfer function with only its transport/UI
# boundaries replaced. The data copying is real rsync between isolated files.
eval "$(sed -n '/^transfer_file(){/,/^}/p' "$ROOT/src/rootfs/usr/local/sbin/unmotion-worker" | sed 's|/usr/local/sbin/unmotion-transport|test_transport|g')"
test_transport(){ printf 'test-transport'; }
sq(){ printf "'%s'" "$1"; }
state(){ :; }; debug(){ :; }; progress_update(){ :; }; human_bytes(){ printf '%s' "$1"; }
stream_progress_parser(){ while IFS= read -r _; do :; done; }
remote(){ if [[ "$1" == 'stat -c %s'* ]]; then if [[ "${bad_size:-0}" == 1 ]]; then printf 1; else stat -c %s -- "$dst"; fi; fi; }
rsync(){
  local -a args=(); local value
  while (($#)); do value="$1"; shift; case "$value" in -e) shift ;; --rsync-path=*) ;; --) break ;; *) args+=("$value") ;; esac; done
  printf '%s\n' "${args[@]}" > "$task_dir/final-args"
  command rsync "${args[@]}" -- "$1" "$dst"
  [[ "${grow_final:-0}" != 1 ]] || truncate -s 22020096 "$1"
}
# Used by the extracted shipped transfer function.
# shellcheck disable=SC2034
RSYNC_RSH=unused REMOTE=unused
printf 'changed' | dd of="$dst" bs=1 conv=notrunc status=none; touch -r "$src" "$dst"
[[ $(stat -c %s "$src") == $(stat -c %s "$dst") && $(stat -c %Y "$src") == $(stat -c %Y "$dst") ]]; ok
transfer_file "$src" "$dst" 15 65 'Squid Proxy image' 1
grep -Fxq -- --ignore-times "$task_dir/final-args"; ok
[[ $(sha256sum "$src" | cut -d' ' -f1) == $(sha256sum "$dst" | cut -d' ' -f1) ]]; ok
transfer_file "$src" "$dst" 15 65 'Cold image' 0
if grep -Fxq -- --ignore-times "$task_dir/final-args"; then echo 'Cold copies unexpectedly force a warm delta' >&2; exit 1; fi; ok
bad_size=1; reject transfer_file "$src" "$dst" 15 65 'Wrong final length' 1; bad_size=0
grow_final=1; reject transfer_file "$src" "$dst" 15 65 'Changing final source' 1
echo "Growing/unchanged provisional seeds, strict transfer statistics, final forced deltas and stopped-source verification passed ($checks)."
