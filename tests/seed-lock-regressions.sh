#!/bin/bash
set -euo pipefail
task_dir=$(mktemp -d)
trap 'rm -f -- "$task_dir/global" "$task_dir/vm" "$task_dir/seed"; rmdir -- "$task_dir"' EXIT
# New independent workers coexist; an old exclusive worker cannot overlap.
exec 9>"$task_dir/global"
flock -sn 9
flock -sn "$task_dir/global" true
if flock -xn "$task_dir/global" true; then echo 'Legacy exclusive worker overlapped shared workers' >&2; exit 1; fi
flock -u 9
flock -xn 9
if flock -sn "$task_dir/global" true; then echo 'Shared worker overlapped legacy exclusive worker' >&2; exit 1; fi
flock -u 9
# The actual per-VM/per-seed primitives must still exclude duplicate ownership.
exec 8>"$task_dir/vm"
exec 7>"$task_dir/seed"
flock -xn 8
flock -xn 7
if flock -xn "$task_dir/vm" true; then echo 'VM lock allowed two owners' >&2; exit 1; fi
if flock -xn "$task_dir/seed" true; then echo 'Seed lock allowed two owners' >&2; exit 1; fi
exec 7>&- 8>&- 9>&-
echo 'Shared compatibility, legacy exclusion and per-VM/per-seed lock regressions passed.'
