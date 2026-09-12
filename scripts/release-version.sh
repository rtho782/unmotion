#!/bin/bash
# SPDX-License-Identifier: GPL-3.0-only
# Copyright (C) 2026 Richard Skinner
# Sourced by the builder and release tests. VERSION is the public/app version.
if [[ ! ${VERSION:-} =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[A-Za-z0-9.]+)?$ ]]; then
  echo 'Invalid release version' >&2
  return 1
fi
PLUGIN_VERSION="$VERSION"
RELEASE_CHANNEL=beta
if [[ "$VERSION" != *-* ]]; then
  # Unraid uses strcmp, not semantic version ordering: plain 0.4.0 < beta4.
  PLUGIN_VERSION="$VERSION-stable"
  RELEASE_CHANNEL=stable
fi
PLUGIN_URL="https://raw.githubusercontent.com/rtho782/unmotion/plugin-$RELEASE_CHANNEL/unmotion.plg"
SAFE_VERSION="${PLUGIN_VERSION//-/_}"
SAFE_VERSION="${SAFE_VERSION,,}"
