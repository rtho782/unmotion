#!/bin/bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
REL="$ROOT/release/0.3.0-beta7"
EXPECTED_VERSION="${1:-0.4.0-beta2}"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

echo 'Checking recovered artifact hashes...'
(cd "$REL" && sha256sum -c SHA256SUMS)

echo 'Extracting the PLG payload...'
sed -n '/<FILE Name="&payload;"><INLINE>/,/<\/INLINE><\/FILE>/p' "$REL/unmotion-0.3.0-beta7.plg" |
  sed '1d;$d' | tr -d '\r\n' | base64 -d > "$TMP/payload.txz"
cmp "$TMP/payload.txz" "$REL/unmotion-0.3.0_beta7-noarch-1.txz"

test "$(tr -d '\r\n' < "$ROOT/src/rootfs/usr/local/emhttp/plugins/unmotion/VERSION")" = "$EXPECTED_VERSION"
grep -Fq "const UNM_VERSION = '$EXPECTED_VERSION';" "$ROOT/src/rootfs/usr/local/emhttp/plugins/unmotion/include/lib.php"
grep -Eq 'const[[:space:]]+UNM_PROTOCOL[[:space:]]*=[[:space:]]*5;' "$ROOT/src/rootfs/usr/local/emhttp/plugins/unmotion/include/lib.php"
grep -Eq 'const[[:space:]]+UNM_REPLICATION_PROTOCOL[[:space:]]*=[[:space:]]*1;' "$ROOT/src/rootfs/usr/local/emhttp/plugins/unmotion/include/lib.php"
grep -q '<txt-record>protocol=5</txt-record>' "$ROOT/src/rootfs/etc/rc.d/rc.unmotion"

if command -v php >/dev/null; then
  find "$ROOT/src/rootfs" -type f \( -name '*.php' -o -name '*.page' \) -print0 |
    while IFS= read -r -d '' file; do php -l "$file" >/dev/null; done
  php "$ROOT/tests/php-regressions.php"
else
  echo 'WARN: PHP unavailable; PHP syntax checks skipped.' >&2
fi

if command -v node >/dev/null; then
  node --check "$ROOT/src/rootfs/usr/local/emhttp/plugins/unmotion/js/unmotion.js"
else
  echo 'WARN: Node.js unavailable; JavaScript syntax check skipped.' >&2
fi

for file in "$ROOT/src/rootfs/etc/rc.d/rc.unmotion" "$ROOT/src/rootfs/usr/local/sbin/"*; do
  if head -n 1 "$file" | grep -q 'php'; then
    if command -v php >/dev/null; then php -l "$file" >/dev/null; fi
  else
    bash -n "$file"
  fi
done

bash "$ROOT/tests/static-regressions.sh"
bash "$ROOT/tests/shell-regressions.sh"

echo 'All available verification checks passed.'
