#!/bin/bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
export LC_ALL=C
VERSION=0.4.0
source "$ROOT/scripts/release-version.sh"
test "$PLUGIN_VERSION" = 0.4.0-stable
test "$SAFE_VERSION" = 0.4.0_stable
test "$RELEASE_CHANNEL" = stable
test "$PLUGIN_URL" = https://raw.githubusercontent.com/rtho782/unmotion/plugin-stable/unmotion.plg
[[ "$PLUGIN_VERSION" > 0.4.0-beta4 ]]
[[ "$PLUGIN_VERSION" > 0.4.0-RC1 ]]
printf '%s\n' unmotion-0.4.0_beta4-noarch-1 unmotion-0.4.0_stable-noarch-1 | sort -V -C
VERSION=0.5.0-beta1
source "$ROOT/scripts/release-version.sh"
test "$PLUGIN_VERSION" = 0.5.0-beta1
test "$SAFE_VERSION" = 0.5.0_beta1
test "$RELEASE_CHANNEL" = beta
[[ "$PLUGIN_VERSION" > 0.4.0-stable ]]
VERSION=0.4.1
source "$ROOT/scripts/release-version.sh"
test "$PLUGIN_VERSION" = 0.4.1-stable
test "$SAFE_VERSION" = 0.4.1_stable
test "$RELEASE_CHANNEL" = stable
test "$PLUGIN_URL" = https://raw.githubusercontent.com/rtho782/unmotion/plugin-stable/unmotion.plg
[[ "$PLUGIN_VERSION" > 0.4.0-stable ]]
[[ "$PLUGIN_VERSION" > 0.4.1-beta1 ]]
[[ "$PLUGIN_VERSION" > 0.4.1-beta2 ]]
printf '%s\n' unmotion-0.4.0_stable-noarch-1 unmotion-0.4.1_beta1-noarch-1 unmotion-0.4.1_beta2-noarch-1 unmotion-0.4.1_stable-noarch-1 | sort -V -C
VERSION=0.4.0-RC1
source "$ROOT/scripts/release-version.sh"
test "$SAFE_VERSION" = 0.4.0_rc1
if (VERSION='../bad'; source "$ROOT/scripts/release-version.sh") 2>/dev/null; then
  echo 'Unsafe version accepted' >&2
  exit 1
fi
echo 'Stable/beta release channels and upgrade ordering passed.'
