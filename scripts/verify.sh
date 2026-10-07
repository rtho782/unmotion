#!/bin/bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
REL="$ROOT/release/0.3.0-beta7"
EXPECTED_VERSION="${1:-0.4.2}"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

echo 'Checking recovered artifact hashes...'
bash "$ROOT/tests/release-version-regressions.sh"
bash "$ROOT/tests/package-regressions.sh"
(cd "$REL" && sha256sum -c SHA256SUMS)

echo 'Extracting the PLG payload...'
sed -n '/<FILE Name="&payload;"><INLINE>/,/<\/INLINE><\/FILE>/p' "$REL/unmotion-0.3.0-beta7.plg" |
  sed '1d;$d' | tr -d '\r\n' | base64 -d > "$TMP/payload.txz"
cmp "$TMP/payload.txz" "$REL/unmotion-0.3.0_beta7-noarch-1.txz"

test "$(tr -d '\r\n' < "$ROOT/src/rootfs/usr/local/emhttp/plugins/unmotion/VERSION")" = "$EXPECTED_VERSION"
grep -Fq "const UNM_VERSION = '$EXPECTED_VERSION';" "$ROOT/src/rootfs/usr/local/emhttp/plugins/unmotion/include/lib.php"
grep -Eq 'const[[:space:]]+UNM_PROTOCOL[[:space:]]*=[[:space:]]*7;' "$ROOT/src/rootfs/usr/local/emhttp/plugins/unmotion/include/lib.php"
grep -Eq 'const[[:space:]]+UNM_REPLICATION_PROTOCOL[[:space:]]*=[[:space:]]*1;' "$ROOT/src/rootfs/usr/local/emhttp/plugins/unmotion/include/lib.php"
grep -q '<txt-record>protocol=7</txt-record>' "$ROOT/src/rootfs/etc/rc.d/rc.unmotion"

if command -v php >/dev/null; then
  find "$ROOT/src/rootfs" -type f \( -name '*.php' -o -name '*.page' \) -print0 |
    while IFS= read -r -d '' file; do php -l "$file" >/dev/null; done
  php "$ROOT/tests/php-regressions.php"
  php "$ROOT/tests/ssh-keys-regressions.php"
  php "$ROOT/tests/transport-test.php"
  if [[ $(id -u) == 0 ]] && command -v rsync >/dev/null; then
    TRANSPORT_TEST_DIR=$(mktemp -d /tmp/unmotion-transport-test.XXXXXX)
    php "$ROOT/tests/transport-integration.php" "$TRANSPORT_TEST_DIR"
    # The integration runner owns only this freshly-created exact directory.
    case "$TRANSPORT_TEST_DIR" in /tmp/unmotion-transport-test.*) rm -rf -- "$TRANSPORT_TEST_DIR" ;; esac
  else echo 'WARN: real chroot/sparse rsync transport tests require root and rsync.' >&2; fi
  php "$ROOT/tests/nvram-protocol-regressions.php"
  php "$ROOT/tests/recovery-disk-binding-regressions.php"
  php "$ROOT/tests/native-grant-regressions.php"
  php "$ROOT/tests/native-fence-regressions.php"
  php "$ROOT/tests/native-fence-controller-test.php"
  php "$ROOT/tests/upgrade-managed-guard-regressions.php"
  php "$ROOT/tests/diagnostic-regressions.php"
  php "$ROOT/tests/seed-concurrency-regressions.php"
  php "$ROOT/tests/seed-archive-regressions.php"
  php "$ROOT/tests/preflight-policy-regressions.php"
  php "$ROOT/tests/cutover-control-regressions.php"
  php "$ROOT/tests/migration-concurrency-regressions.php"
  php "$ROOT/tests/process-identity-regressions.php"
  if [[ $(id -u) == 0 ]] && command -v unshare >/dev/null; then
    unshare --mount --pid --fork --mount-proc --propagation private bash "$ROOT/tests/process-service-regressions.sh"
  else echo 'WARN: isolated process service/upgrade tests require root and unshare.' >&2; fi
  if [[ -w /mnt ]]; then
    php "$ROOT/tests/nvram-regressions.php"
    php "$ROOT/tests/nvram-destination-regressions.php"
    php "$ROOT/tests/portability-regressions.php"
    php "$ROOT/tests/migration-resolution-regressions.php"
  else echo 'WARN: /mnt is not writable; run the NVRAM filesystem regressions with privileges on the development host.' >&2; fi
else
  echo 'WARN: PHP unavailable; PHP syntax checks skipped.' >&2
fi

if command -v node >/dev/null; then
  node --check "$ROOT/src/rootfs/usr/local/emhttp/plugins/unmotion/js/unmotion.js"
  node --check "$ROOT/src/rootfs/usr/local/emhttp/plugins/unmotion/js/report.js"
  node "$ROOT/tests/ui-warning-regressions.js"
  node "$ROOT/tests/ui-escaping-regressions.js"
else
  echo 'WARN: Node.js unavailable; JavaScript syntax check skipped.' >&2
fi

for file in "$ROOT/src/rootfs/etc/rc.d/rc.unmotion" "$ROOT/src/rootfs/usr/local/sbin/"* "$ROOT/src/rootfs/etc/libvirt/hooks/qemu.d/"*; do
  if head -n 1 "$file" | grep -q 'php'; then
    if command -v php >/dev/null; then php -l "$file" >/dev/null; fi
  else
    bash -n "$file"
  fi
done

bash "$ROOT/tests/static-regressions.sh"
bash "$ROOT/tests/shell-regressions.sh"
bash "$ROOT/tests/seed-lock-regressions.sh"
bash "$ROOT/tests/migration-lock-regressions.sh"
bash "$ROOT/tests/numeric-regressions.sh"
bash "$ROOT/tests/completed-receive-regressions.sh"
bash "$ROOT/tests/start-gate-regressions.sh"
bash -n "$ROOT/src/rootfs/usr/local/emhttp/plugins/unmotion/include/numeric.sh"
bash -n "$ROOT/src/rootfs/usr/local/emhttp/plugins/unmotion/include/migration-lock.sh"

echo 'All available verification checks passed.'
