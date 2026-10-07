#!/bin/bash
set -euo pipefail
# Run under sudo unshare --mount --pid --fork --mount-proc --propagation private.
# No service or package is
# installed: only this mount namespace sees the /usr/local test overlay.
[[ $(id -u) == 0 && $$ == 1 ]] || { echo 'Run process service tests as PID 1 in private root PID/mount namespaces.' >&2; exit 2; }
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TMP="$(mktemp -d /tmp/unmotion-process-service.XXXXXX)"
mounted=0; boot_mounted=0; lib_mounted=0
cleanup(){ ((lib_mounted==0)) || umount /var/lib; ((boot_mounted==0)) || umount /boot; ((mounted==0)) || umount /usr/local; rm -rf -- "$TMP"; }
trap cleanup EXIT
mkdir -p "$TMP/local/sbin" "$TMP/local/emhttp/plugins/unmotion/include" "$TMP/boot" "$TMP/var-lib"
cp "$ROOT/src/rootfs/usr/local/emhttp/plugins/unmotion/include/process-identity.php" "$TMP/local/emhttp/plugins/unmotion/include/"
cp "$ROOT/src/rootfs/usr/local/sbin/unmotion-process" "$TMP/local/sbin/"
for script in unmotion-replication-scheduler unmotion-replication-lifecycle unmotion-worker unmotion-ssh-gate unmotion-agent unmotion-start-destination; do cp "$ROOT/tests/fixtures/process-service-worker.php" "$TMP/local/sbin/$script"; done
chmod 0755 "$TMP/local/sbin/"*
mount --bind "$TMP/local" /usr/local
mounted=1
mount --bind "$TMP/boot" /boot
boot_mounted=1
mount --bind "$TMP/var-lib" /var/lib
lib_mounted=1
php "$ROOT/tests/process-service-regressions.php" "$TMP"
