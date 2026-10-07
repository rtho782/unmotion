#!/bin/bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
function_text=$(sed -n '/^completed_zfs_receive(){/,/^}/p' "$ROOT/src/rootfs/usr/local/sbin/unmotion-worker")
[[ -n "$function_text" ]]
eval "$function_text"
JOB_ID=20261003-test
SNAPSHOT_NAME="unmotion-$JOB_ID"
source_guid=12345678901234567890
destination_guid=$source_guid
source_code=0
destination_code=0
zfs(){ printf '%s\n' "$source_guid"; return "$source_code"; }
remote(){ printf '%s\n' "$destination_guid"; return "$destination_code"; }
sq(){ printf "'%s'" "$1"; }
log(){ :; }
completed_zfs_receive 'cache/Squid Proxy' 'target/Squid Proxy'
destination_guid=123
if completed_zfs_receive 'cache/Squid Proxy' 'target/Squid Proxy'; then echo 'Mismatched GUID accepted' >&2; exit 1; fi
destination_guid=$source_guid
source_code=1
if completed_zfs_receive src dst; then echo 'Missing source snapshot accepted' >&2; exit 1; fi
source_code=0; destination_code=1
if completed_zfs_receive src dst; then echo 'Missing destination snapshot accepted' >&2; exit 1; fi
destination_code=0; SNAPSHOT_NAME=unmotion-other-job
if completed_zfs_receive src dst; then echo 'Other job snapshot accepted' >&2; exit 1; fi
SNAPSHOT_NAME="unmotion-$JOB_ID"; source_guid=''; destination_guid=''
if completed_zfs_receive src dst; then echo 'Empty GUID accepted' >&2; exit 1; fi
echo 'Exact-job completed ZFS receive admission regressions passed.'
