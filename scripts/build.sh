#!/bin/bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VERSION="${1:-$(tr -d '\r\n' < "$ROOT/src/rootfs/usr/local/emhttp/plugins/unmotion/VERSION")}"
AUTHOR="Richard Skinner"
test "$VERSION" = "$(tr -d '\r\n' < "$ROOT/src/rootfs/usr/local/emhttp/plugins/unmotion/VERSION")"
source "$ROOT/scripts/release-version.sh"
PKG="unmotion-${SAFE_VERSION}-noarch-1.txz"
PLG="unmotion-${VERSION}.plg"
STAGE="$ROOT/work/package-root"
DIST="$ROOT/dist"

rm -rf "$STAGE"
mkdir -p "$STAGE" "$DIST"
cp -a "$ROOT/src/rootfs/." "$STAGE/"
cp "$ROOT/LICENSE" "$STAGE/usr/local/emhttp/plugins/unmotion/LICENSE"
cp "$STAGE/etc/libvirt/hooks/qemu.d/50-unmotion-recovery" "$STAGE/usr/local/emhttp/plugins/unmotion/include/native-qemu-hook"

find "$STAGE" -type d -exec chmod 0755 {} +
chmod 0755 "$STAGE/etc/rc.d/rc.unmotion" "$STAGE/install/doinst.sh" "$STAGE/usr/local/sbin/"*
chmod 0755 "$STAGE/etc/libvirt/hooks/qemu.d/50-unmotion-recovery"
chmod 0755 "$STAGE/usr/local/emhttp/plugins/dynamix.plugin.manager/post-hooks/unmotion-package-cache"
find "$STAGE/usr/local/emhttp/plugins/unmotion" -type f -exec chmod 0644 {} +

# Syntax checks alone accept CRLF PHP shebangs, but Linux cannot execute them.
# Reject bad exports before an archive or descriptor can be published.
while IFS= read -r -d '' file; do
  IFS= read -r first_line < "$file" || true
  if [[ "$first_line" == '#!'* && "$first_line" == *$'\r'* ]]; then
    echo "CRLF interpreter line in package source: $file" >&2
    exit 1
  fi
done < <(find "$STAGE" -type f -print0)

tar -C "$STAGE" --owner=0 --group=0 -cJf "$DIST/$PKG" .
if tar -tvf "$DIST/$PKG" | awk '$1 ~ /^d/ && $1 != "drwxr-xr-x" {print "Unsafe package directory mode: " $0 > "/dev/stderr"; bad=1} END {exit bad?1:0}'; then :; else
  rm -f "$DIST/$PKG"
  exit 1
fi
SHA="$(sha256sum "$DIST/$PKG" | awk '{print $1}')"
# The package is published as an asset of the GitHub release tagged $VERSION; Unraid downloads it and checks the SHA-256.
PKG_URL="https://github.com/rtho782/unmotion/releases/download/$VERSION/$PKG"

{
cat <<EOF
<?xml version='1.0' standalone='yes'?>
<!DOCTYPE PLUGIN [
<!ENTITY name "unmotion">
<!ENTITY author "$AUTHOR">
<!ENTITY version "$PLUGIN_VERSION">
<!ENTITY launch "UnMotion">
<!ENTITY plgdir "/boot/config/plugins/&name;">
<!ENTITY package "&plgdir;/packages/$PKG">
]>
<PLUGIN name="&name;" author="&author;" version="&version;" pluginURL="$PLUGIN_URL" launch="&launch;" min="7.3.2" icon="exchange" support="https://github.com/rtho782/unmotion/issues">
<CHANGES>
### unMotion $VERSION
- Explicitly review and reclaim proven inactive live-migration copies on the non-owner host. Exact per-object identity, fresh paired ownership callbacks, short owner leases and retryable deletion receipts protect active data; native start fences and job history remain.
- Older zvol GUID records can prove individual volumes. Unrecorded legacy file/TPM/NVRAM identities remain protected instead of being adopted by filename. No automatic or recursive cleanup is introduced.
- Require a durable successful native finish receipt bound to the exact receiver boot, QEMU process and storage/XML contract before committing an uncommitted receiver. Paused state or received-byte counters alone do not prove transfer completion.
- Experimental native software-TPM 2.0 live migration with fresh matching swtpm/libtpms checks, independent job/UUID-scoped destination state, exact native start fencing and retained source/partial state. No shared storage is required. Encrypted/custom-profile TPM backends remain excluded.
- Live migration supports one to sixteen independent raw/qcow2 file disks or whole raw ZFS volumes, including mixed guests. Zvols remain zvols, with independent job-owned destinations and full reservations; unrelated block devices and attached ISOs remain excluded.
- Fresh ZFS GUID/geometry and kernel device-lifetime checks protect zvol ownership. Capacity checks combine file/zvol budgets on shared destination pools, include allocation overhead and 20% free-space headroom, and preserve the destination dedup policy.
- Display disk-copy and RAM pre-copy/convergence statistics separately; retransmitted bytes are not an overall completion percentage or a promised ETA.
- Review live readiness even when a VM is ineligible. Show independent CPU, TPM, storage, device and network blockers; submission stays disabled until fresh preflight passes, and Start rechecks both hosts.
- Record source-side replica point counts from acknowledged destination inventory, including retention pruning, without treating display evidence as recovery authority.
- Preserve protocol 7. Live capability 4 requires matching Beta9 peers; retained-copy cleanup separately requires liveCleanupVersion 1. Ordinary supported Cold/Warm Move, clone, replication and retirement workflows retain their capability gates.
- Preserve CPU defaults, reviewed BIOS/UEFI firmware, nested Intel validation, paused receivers, durable ownership and explicit reconciliation. AMD nested live migration, passthrough, automatic failover and recovery arming after live hand-back remain excluded.
- Source/partial live disks, NVRAM and TPM state are retained. No automatic source-disk reclamation or shared-storage service is introduced.
- Requires Unraid 7.3.2 or later. Package downloads are SHA-256 pinned; the verified current TXZ is retained for offline boots. Author: Richard Skinner.
</CHANGES>
EOF
printf '%s' '<FILE Run="/usr/bin/php"><INLINE><![CDATA['
# Readable source, not an embedded archive. This guard must execute BEFORE
# upgradepkg replaces running scripts or the security upgrade revokes old keys.
cat "$ROOT/src/rootfs/usr/local/emhttp/plugins/unmotion/include/process-identity.php"
sed '1,2d' "$ROOT/src/rootfs/usr/local/emhttp/plugins/unmotion/include/process-upgrade.php"
cat <<'EOF'
try { unmProcessPreinstall(); } catch (Throwable $error) { fwrite(STDERR, $error->getMessage()."\n"); exit(1); }
]]></INLINE></FILE>
EOF
cat <<EOF
<FILE Name="&package;" Run="/sbin/upgradepkg --install-new" Mode="0600">
<URL>$PKG_URL</URL>
<SHA256>$SHA</SHA256>
</FILE>
<FILE Run="/bin/bash"><INLINE>
/etc/rc.d/rc.unmotion restart || exit 1
</INLINE></FILE>
<FILE Run="/bin/bash" Method="remove"><INLINE>
/usr/local/sbin/unmotion-native-fence check-remove || exit 1
/usr/local/sbin/unmotion-cpu-editor restore || exit 1
/etc/rc.d/rc.unmotion stop || exit 1
/usr/local/sbin/unmotion-cleanup || exit 1
/sbin/removepkg unmotion 2>/dev/null || true
rm -f /usr/local/emhttp/plugins/dynamix.plugin.manager/post-hooks/unmotion-package-cache
rm -f /etc/libvirt/hooks/qemu.d/50-unmotion-recovery
rm -rf /usr/local/emhttp/plugins/unmotion /usr/local/sbin/unmotion-* /etc/rc.d/rc.unmotion
rm -rf "&plgdir;"
</INLINE></FILE>
</PLUGIN>
EOF
} > "$DIST/$PLG"

sha256sum "$DIST/$PKG" "$DIST/$PLG"
