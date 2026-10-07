#!/bin/bash
# SPDX-License-Identifier: GPL-3.0-only
# Copyright (C) 2026 Richard Skinner
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_MANAGER_SOURCE="${UNM_PLUGIN_MANAGER_SOURCE:-/usr/local/emhttp/plugins/dynamix.plugin.manager/scripts/plugin}"
if [[ -n "${UNM_PLUGIN_MANAGER_SOURCE:-}" && ! -f "$PLUGIN_MANAGER_SOURCE" ]]; then
  echo 'Configured native Unraid plugin-manager test source is missing.' >&2
  exit 1
fi
if [[ ! -f "$PLUGIN_MANAGER_SOURCE" ]]; then
  echo 'WARN: native Unraid plugin-manager download/cache checks skipped; supply UNM_PLUGIN_MANAGER_SOURCE or run on Unraid.' >&2
fi
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
mkdir -p "$TMP/scripts" "$TMP/src"
cp "$ROOT/scripts/build.sh" "$ROOT/scripts/release-version.sh" "$TMP/scripts/"
cp "$ROOT/LICENSE" "$TMP/"
cp -a "$ROOT/src/rootfs" "$TMP/src/"
bash -n "$TMP/scripts/build.sh"
bash -n "$TMP/scripts/release-version.sh"

export RELEASE_METADATA_FILE="$TMP/RELEASE_ID"
for version in 0.4.3 0.5.0-beta1; do
  printf '%s\n' "$version" > "$TMP/src/rootfs/usr/local/emhttp/plugins/unmotion/VERSION"
  printf '%s %s\n' "$version" 2026.10.04.01 > "$RELEASE_METADATA_FILE"
  bash "$TMP/scripts/build.sh" "$version" >/dev/null
  VERSION="$version" source "$TMP/scripts/release-version.sh"
  package="$TMP/dist/unmotion-${SAFE_VERSION}-noarch-1.txz"
  descriptor="$TMP/dist/unmotion-${version}.plg"
  if command -v php >/dev/null; then
    php "$ROOT/tests/package-regressions.php" "$descriptor" "$package" "$version" "$INSTALLER_BUILD_ID"
    if [[ -f "$PLUGIN_MANAGER_SOURCE" ]]; then
      php -d display_errors=1 -d log_errors=0 "$ROOT/tests/package-manager-regressions.php" "$descriptor" "$package" "$PLUGIN_MANAGER_SOURCE"
    fi
  else
    echo 'WARN: PHP unavailable; generated PLG XML/checksum regressions skipped.' >&2
  fi
done
if bash "$TMP/scripts/build.sh" '../invalid' >/dev/null 2>&1; then
  echo 'Unsafe build version accepted' >&2
  exit 1
fi
echo 'Release-asset packaging regressions passed.'
