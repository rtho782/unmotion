#!/bin/bash
# SPDX-License-Identifier: GPL-3.0-only
# Copyright (C) 2026 Richard Skinner
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
export LC_ALL=C
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT
export RELEASE_METADATA_FILE="$TMP/RELEASE_ID"
load_version() {
  VERSION="$1"
  printf '%s %s\n' "$VERSION" "$2" > "$RELEASE_METADATA_FILE"
  source "$ROOT/scripts/release-version.sh"
}
load_version 0.4.3 2026.10.04.01
test "$PLUGIN_VERSION" = 2026.10.04.01-0.4.3
test "$SAFE_VERSION" = 0.4.3_stable
test "$RELEASE_CHANNEL" = stable
test "$PLUGIN_URL" = https://raw.githubusercontent.com/rtho782/unmotion/plugin-stable/unmotion.plg
for legacy in 0.3.0-RC1 0.4.0-stable 0.4.1-beta10 0.4.2-beta1 0.4.2-stable; do [[ "$PLUGIN_VERSION" > "$legacy" ]]; done
first="$PLUGIN_VERSION"
source "$ROOT/scripts/release-version.sh"
test "$PLUGIN_VERSION" = "$first"
previous=''
sequence=1
for version in 0.4.4-beta9 0.4.4-beta10 0.4.4-RC1 0.4.4 0.4.9 0.4.10 0.9.0 0.10.0; do
  id=$(printf '2026.10.05.%02d' "$sequence")
  load_version "$version" "$id"
  [[ -z "$previous" || "$PLUGIN_VERSION" > "$previous" ]]
  previous="$PLUGIN_VERSION"
  sequence=$((sequence+1))
done
load_version 0.5.0-beta10 2026.10.06.01
test "$SAFE_VERSION" = 0.5.0_beta10
test "$RELEASE_CHANNEL" = beta
test "$PLUGIN_URL" = https://raw.githubusercontent.com/rtho782/unmotion/plugin-beta/unmotion.plg
printf '%s\n' unmotion-0.4.2_stable-noarch-1 unmotion-0.4.3_stable-noarch-1 unmotion-0.4.9_stable-noarch-1 unmotion-0.4.10_stable-noarch-1 unmotion-0.5.0_beta9-noarch-1 unmotion-0.5.0_beta10-noarch-1 unmotion-0.5.0_stable-noarch-1 | sort -V -C
VERSION=0.4.3
for invalid in '0.4.2 2026.10.04.01' '0.4.3 2026.02.31.01' '0.4.3 2026.10.04.00' '0.4.3 2026.10.04.100' '0.4.3 2026.1.04.01' '0.4.3 2026.10.04.01 extra'; do
  printf '%s\n' "$invalid" > "$RELEASE_METADATA_FILE"
  if (source "$ROOT/scripts/release-version.sh") 2>/dev/null; then echo "Invalid release metadata accepted: $invalid" >&2; exit 1; fi
done
if (VERSION='../bad'; source "$ROOT/scripts/release-version.sh") 2>/dev/null; then exit 1; fi
rm "$RELEASE_METADATA_FILE"
if (source "$ROOT/scripts/release-version.sh") 2>/dev/null; then echo 'Missing release metadata accepted' >&2; exit 1; fi
echo 'Fixed installer IDs, legacy upgrade, double-digit ordering, channels, rebuild and metadata validation passed.'
