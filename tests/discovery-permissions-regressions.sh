#!/bin/bash
# SPDX-License-Identifier: GPL-3.0-only
# Copyright (C) 2026 Richard Skinner
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
RC="${UNM_DISCOVERY_TEST_RC:-$ROOT/src/rootfs/etc/rc.d/rc.unmotion}"
TMP=$(mktemp -d)
trap 'rm -rf -- "$TMP"' EXIT
umask 077
mkdir -p "$TMP/Squid Proxy" "$TMP/private"
SERVICE="$TMP/Squid Proxy/unmotion.service"
PLUGIN="$TMP/private"
printf '%s\n' 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa' > "$PLUGIN/host_id"
printf '%s\n' 'private fixture' > "$PLUGIN/key"
grep -qx 'umask 077' "$RC"

# Exercise the production advertise function without changing system services,
# sending real signals, or invoking any migration/recovery startup operations.
mkdir() { [[ "$*" == '-p /etc/avahi/services' ]]; }
hostname() { printf '%s\n' 'Squid Proxy'; }
php() { printf '%s' "$DISCOVERY"; }
sshd() { printf 'port 2222\n'; }
cat() {
  if [[ ${FAIL_WRITE:-0} == 1 && $# == 0 ]]; then return 1; fi
  if [[ "${1:-}" == /usr/local/emhttp/plugins/unmotion/VERSION ]]; then
    printf '0.4.4\n'
  else command cat "$@"; fi
}
pidof() { [[ "$1" == avahi-daemon ]] && printf '98765 98764\n'; }
kill() { printf 'Do not signal the first Avahi PID (it can be the helper)\n' >&2; exit 1; }
mv() {
  [[ "$1" == '-f' && "$2" == '--' && "$4" == "$SERVICE" ]]
  [[ "$3" == "${SERVICE%/*}/.unmotion."* ]]
  [[ $(stat -c %a "$3") == 644 ]]
  grep -Fq '</service-group>' "$3"
  [[ ${FAIL_RENAME:-0} != 1 ]] || return 1
  command mv "$@"
}
chmod() {
  [[ ${FAIL_MODE:-0} != 1 || "$1" != 0644 ]] || return 1
  command chmod "$@"
}
avahi-daemon() {
  [[ "$*" == '--reload' ]]
  # Permissions must be fixed before requesting that Avahi reread the file.
  if [[ -f "$SERVICE" ]]; then
    [[ $(stat -c %a "$SERVICE") == 644 ]] || { printf 'Advertisement is unreadable at reload\n' >&2; exit 1; }
  fi
  printf 'reload\n' >> "$TMP/reloads"
}
# The Linux test host need not have Avahi installed. Bypass only its absolute
# system-path availability guard; all file publication logic stays unchanged.
advertise_source=$(sed -n '/^advertise(){/,/^}/p' "$RC" | sed '/^  \[\[ -x \/usr\/bin\/avahi-browse /d')
[[ -n "$advertise_source" ]]
eval "$advertise_source"
DISCOVERY=1

assert_public_only() {
  [[ $(stat -c %a "$SERVICE") == 644 ]]
  [[ $(stat -c %a "$PLUGIN/key") == 600 ]]
  [[ $(stat -c %a "$PLUGIN/host_id") == 600 ]]
  [[ $(stat -c %a "$PLUGIN") == 700 ]]
  [[ $(umask) == 0077 ]]
  [[ -z $(find "${SERVICE%/*}" -name '.unmotion.*' -print -quit) ]]
  grep -Fq 'Squid Proxy unMotion' "$SERVICE"
  grep -Fq '<port>2222</port>' "$SERVICE"
  grep -Fq '<txt-record>protocol=7</txt-record>' "$SERVICE"
}

# Fresh creation with the production restrictive umask (install/boot path).
advertise
assert_public_only
first=$(sha256sum "$SERVICE")
# Repair a root-only file left by a previous version (update path).
chmod 0600 "$SERVICE"
advertise
assert_public_only
[[ $(sha256sum "$SERVICE") == "$first" ]]
# Existing readable files and repeated starts stay idempotent.
advertise
assert_public_only
[[ $(wc -l < "$TMP/reloads") == 3 ]]
# Failed staging must leave the old valid advertisement and no temporary file.
for failure in FAIL_WRITE FAIL_MODE FAIL_RENAME; do
  printf -v "$failure" '%s' 1
  if advertise; then printf 'Failed publication was accepted\n' >&2; exit 1; fi
  printf -v "$failure" '%s' 0
  assert_public_only
  [[ $(sha256sum "$SERVICE") == "$first" ]]
  [[ $(wc -l < "$TMP/reloads") == 3 ]]
done
# A fresh boot recreates the file, rather than relying on last boot's mode.
rm -- "$SERVICE"
advertise
assert_public_only
[[ $(wc -l < "$TMP/reloads") == 4 ]]
# Disabled discovery removes its advertisement without exposing private state.
DISCOVERY=0
advertise
[[ ! -e "$SERVICE" ]]
[[ $(wc -l < "$TMP/reloads") == 4 ]]
[[ $(stat -c %a "$PLUGIN/key") == 600 ]]
! grep -Fq 'pidof avahi-daemon' "$RC"
printf 'Atomic discovery creation, upgrade repair, reboot recreation, publication failures, daemon reload, private permissions and disabled-state regressions passed.\n'
