#!/bin/bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
GO="${UNM_GO:-go}"
OUTPUT="${1:?native output path required}"
VERSION="${2:?application version required}"
[[ "$OUTPUT" == /* ]] || { echo 'Native output must be absolute' >&2; exit 1; }
command -v "$GO" >/dev/null || { echo 'Go is required to build the native live transport' >&2; exit 1; }
mkdir -p "$(dirname "$OUTPUT")"
(cd "$ROOT/native/live-stream" && GOTOOLCHAIN=local CGO_ENABLED=0 GOOS=linux GOARCH=amd64 GOAMD64=v1 "$GO" build -trimpath -buildvcs=false -ldflags="-s -w -buildid= -X main.appVersion=$VERSION" -o "$OUTPUT" .)
chmod 0755 "$OUTPUT"
