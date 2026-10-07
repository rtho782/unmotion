#!/bin/bash
# SPDX-License-Identifier: GPL-3.0-only
# Copyright (C) 2026 Richard Skinner
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
source "$ROOT/src/rootfs/usr/local/emhttp/plugins/unmotion/include/numeric.sh"
reject(){ if "$@" >/dev/null 2>&1; then printf 'Unexpected numeric acceptance: %s\n' "$*" >&2; exit 1; fi; }
[[ "$(unm_uint 0008)" == 8 && "$(unm_uint 000000)" == 0 ]]
[[ "$(unm_uint 9223372036854775807)" == 9223372036854775807 ]]
for malformed in '' '-1' '+1' '1.0' '1e3' ' 1' '1 ' '0x10' '1+1' 'destination_memory' 'a[$(false)]' $'1\n2' 9223372036854775808 18446744073709551616; do reject unm_uint "$malformed"; done
reject unm_uint 1025 1024
[[ "$(unm_uint_add 0008 09)" == 17 && "$(unm_uint_add 9223372036854775806 1)" == 9223372036854775807 ]]
reject unm_uint_add 9223372036854775807 1
[[ "$(unm_uint_multiply 0008 0004096)" == 32768 && "$(unm_uint_multiply 0 9223372036854775807)" == 0 ]]
reject unm_uint_multiply 2251799813685248 4096
reject unm_uint_multiply 'a[$(false)]' 4096
[[ "$(unm_stat_available_bytes '0001 0008 0004096')" == 32768 ]]
[[ "$(unm_stat_available_bytes $'1\t8\t4096')" == 32768 ]]
[[ "$(unm_stat_available_bytes '1 0 4096')" == 0 ]]
for malformed in '1 2' '1 2 3 4' '1 2 0' '1 nope 4096' '1 9223372036854775808 1' '1 2251799813685248 4096' $'1 2 4096\n1 2 4096' '1 a[$(false)] 4096'; do reject unm_stat_available_bytes "$malformed"; done
[[ "$(unm_progress_percent 008)" == 8 && "$(unm_progress_percent 101)" == 100 ]]
reject unm_progress_percent 999999999999999999999

# Exercise the actual migration capacity gate with failed/malformed peer replies,
# not just the helper. No live SSH/ZFS/libvirt operations are performed.
eval "$(sed -n '/^check_capacity(){/,/^}/p' "$ROOT/src/rootfs/usr/local/sbin/unmotion-worker")"
log(){ :; }; human_bytes(){ printf '%s' "$1"; }; sq(){ printf "'%s'" "$1"; }
remote(){ case "$1" in stat*) printf '%s' "$IMAGE_RESPONSE"; return "$IMAGE_STATUS";; zfs*) printf '%s' "$ZVOL_RESPONSE"; return "$ZVOL_STATUS";; *) return 99;; esac; }
WARM_MODE=0; REQ_IMAGE=4096; REQ_ZVOL=4096; REQ_STAGE=0
DEST_IMAGE_DIR='/mnt/pool/Squid Proxy'; DEST_ZVOL_DATASET='pool/Squid Proxy'
IMAGE_RESPONSE='1 008 4096'; IMAGE_STATUS=0; ZVOL_RESPONSE=0008192; ZVOL_STATUS=0
check_capacity
IMAGE_STATUS=255; reject check_capacity; IMAGE_STATUS=0
IMAGE_RESPONSE='1 a[$(exit 77)] 4096'; reject check_capacity
IMAGE_RESPONSE='1 2251799813685248 4096'; reject check_capacity
IMAGE_RESPONSE='1 8 4096'; ZVOL_STATUS=255; reject check_capacity; ZVOL_STATUS=0
ZVOL_RESPONSE='1+8192'; reject check_capacity
ZVOL_RESPONSE=9223372036854775808; reject check_capacity
ZVOL_RESPONSE=1024; reject check_capacity
printf '%s\n' 'Numeric normalization, bounds/overflow, malformed peer responses and capacity failure regressions passed.'
