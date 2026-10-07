#!/bin/bash
# SPDX-License-Identifier: GPL-3.0-only
# Copyright (C) 2026 Richard Skinner
set -Eeuo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TMP="$(mktemp -d /tmp/unmotion-start-gate-test.XXXXXX)"
trap 'rm -f -- "$TMP/footer" "$TMP/permit" "$TMP/error"; rmdir -- "$TMP"' EXIT
# Exercise the actual gate footer without host policy/libvirt mutations.
sed -n '/^# Drain virsh output/,$p' "$ROOT/src/rootfs/usr/local/sbin/unmotion-replication-start-gate" > "$TMP/footer"
[[ -s "$TMP/footer" ]]
timeout(){ shift; "$@"; }
virsh(){
  case "$GATE_CASE" in
    disabled)
      printf 'Autostart:      disable\nAutostart Once: enable\n'
      # More than a pipe buffer: an early-exiting parser makes this producer
      # fail under pipefail, even though the first value was valid.
      for ((line=0;line<10000;line++)); do printf 'Additional libvirt diagnostic field: value\n'; done ;;
    enabled) printf 'Autostart: enable\nAutostart Once: disable\n' ;;
    missing) printf 'Autostart Once: disable\n' ;;
    failed) printf 'Autostart: disable\n'; return 1 ;;
  esac
}
export -f timeout virsh
export vm_uuid=74200000-1111-4111-8111-000000000006 permit="$TMP/permit"
export GATE_CASE=disabled
bash -Eeuo pipefail "$TMP/footer"
# The persistent lifecycle uses the same full-output, exact-field rule.
source <(sed -n '/^autostart_state(){/p' "$ROOT/src/rootfs/usr/local/sbin/unmotion-replication-lifecycle")
[[ "$(autostart_state "$vm_uuid")" == disable ]]
for entry in enabled:69 missing:68 failed:68; do
  export GATE_CASE="${entry%:*}"; expected="${entry#*:}"
  printf 'fixture permit\n' > "$permit"
  result=0; bash -Eeuo pipefail "$TMP/footer" 2>"$TMP/error" || result=$?
  [[ "$result" == "$expected" && ! -e "$permit" && -s "$TMP/error" ]] || { echo "Invalid start gate outcome for $GATE_CASE ($result)" >&2; exit 1; }
done
echo 'Start gate autostart drain, exact field, failure and permit-revocation regressions passed.'
