#!/bin/bash
# SPDX-License-Identifier: GPL-3.0-only
# Copyright (C) 2026 Richard Skinner
# Sourced by the builder and release tests. VERSION is the public/app version.
# RELEASE_ID is committed, never generated from the build machine's clock.
if [[ ! ${VERSION:-} =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[A-Za-z0-9.]+)?$ ]]; then
  echo 'Invalid release version' >&2
  return 1
fi
RELEASE_METADATA_FILE="${RELEASE_METADATA_FILE:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/RELEASE_ID}"
if [[ ! -f "$RELEASE_METADATA_FILE" ]]; then
  echo 'Missing committed RELEASE_ID metadata' >&2
  return 1
fi
release_metadata="$(tr -d '\r' < "$RELEASE_METADATA_FILE")"
if [[ ! "$release_metadata" =~ ^([^[:space:]]+)[[:blank:]]([0-9]{4}\.[0-9]{2}\.[0-9]{2}\.[0-9]{2})$ ]]; then
  echo 'Invalid RELEASE_ID: expected application version and YYYY.MM.DD.NN' >&2
  return 1
fi
release_app_version="${BASH_REMATCH[1]}"
INSTALLER_BUILD_ID="${BASH_REMATCH[2]}"
release_date="${INSTALLER_BUILD_ID:0:10}"
if [[ "$release_app_version" != "$VERSION" || "${INSTALLER_BUILD_ID:11:2}" == 00 ]] ||
   [[ "$(date -u -d "${release_date//./-}" +%Y.%m.%d 2>/dev/null)" != "$release_date" ]]; then
  echo 'RELEASE_ID must match VERSION and contain a valid date and sequence 01..99' >&2
  return 1
fi
PLUGIN_VERSION="$INSTALLER_BUILD_ID-$VERSION"
# Keep Slackware package filenames/version ordering independent of the PLG date.
PACKAGE_VERSION="$VERSION"
RELEASE_CHANNEL=beta
if [[ "$VERSION" != *-* ]]; then
  PACKAGE_VERSION="$VERSION-stable"
  RELEASE_CHANNEL=stable
fi
PLUGIN_URL="https://raw.githubusercontent.com/rtho782/unmotion/plugin-$RELEASE_CHANNEL/unmotion.plg"
SAFE_VERSION="${PACKAGE_VERSION//-/_}"
SAFE_VERSION="${SAFE_VERSION,,}"
