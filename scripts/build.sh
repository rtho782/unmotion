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
bash "$ROOT/scripts/build-native.sh" "$STAGE/usr/local/sbin/unmotion-live-stream" "$VERSION"
mkdir -p "$STAGE/usr/local/emhttp/plugins/unmotion/licenses"
cp "$("${UNM_GO:-go}" env GOROOT)/LICENSE" "$STAGE/usr/local/emhttp/plugins/unmotion/licenses/Go-LICENSE"

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
- A completed live destination becomes a normal VM without reboot or a separate hand-back. Successful moves automatically clean up the exact obsolete source disks/NVRAM/TPM after transfer sessions close; blocked cleanup is reported separately, with old-copy start fences/history retained. Failed or unresolved transfers retain their data.
- Permit same-vendor masked guests to move to an older physical CPU generation only after fresh complete guest CPU/nested validation. Repeat validation at receiver reservation; no physical-family ranking substitutes for compatibility proof.
- Native OpenSSH/libvirt live migration without a custom payload relay, RPC inspector or extra application-controlled pause. Both peers must enable administrator-equivalent native migration trust. Disposable source-bound keys, persistent start fences and authenticated ownership reconciliation remain; this is not a per-path sandbox.
- Settings selects AES-128-GCM (default) or AES-256-GCM for outgoing paired-host SSH connections, including Cold/Warm Move and replication. Cipher performance depends on the hosts and workload; no plaintext or silent fallback is offered.
- Explicitly review and reclaim proven inactive live-migration copies on the non-owner host. Exact per-object identity, fresh paired ownership callbacks, short owner leases and retryable deletion receipts protect active data; native start fences and job history remain.
- Older zvol GUID records can prove individual volumes. Unrecorded legacy file/TPM/NVRAM identities remain protected instead of being adopted by filename. No recursive cleanup is introduced; new successful jobs use the exact automatic cleanup described above.
- Require a durable successful native finish receipt bound to the exact receiver boot, QEMU process and storage/XML contract before committing an uncommitted receiver. Paused state or received-byte counters alone do not prove transfer completion.
- Experimental native software-TPM 2.0 live migration with fresh matching swtpm/libtpms checks, independent job/UUID-scoped destination state, exact native start fencing and retained source/partial state. No shared storage is required. Encrypted/custom-profile TPM backends remain excluded.
- Live migration supports one to sixteen independent raw/qcow2 file disks or whole raw ZFS volumes, including mixed guests. Zvols remain zvols, with independent job-owned destinations and full reservations; unrelated block devices and attached ISOs remain excluded.
- Fresh ZFS GUID/geometry and kernel device-lifetime checks protect zvol ownership. Capacity checks combine file/zvol budgets on shared destination pools, include allocation overhead and 20% free-space headroom, and preserve the destination dedup policy.
- Display disk-copy and RAM pre-copy/convergence statistics separately; retransmitted bytes are not an overall completion percentage or a promised ETA.
- Review live readiness even when a VM is ineligible. Show independent CPU, TPM, storage, device and network blockers; submission stays disabled until fresh preflight passes, and Start rechecks both hosts.
- Record source-side replica point counts from acknowledged destination inventory, including retention pruning, without treating display evidence as recovery authority.
- Preserve protocol 7. Live capability 4 requires matching $VERSION peers and native-data capability 1; retained-copy cleanup separately requires liveCleanupVersion 1. Ordinary supported Cold/Warm Move, clone, replication and retirement workflows retain their capability gates.
- Preserve CPU defaults, reviewed BIOS/UEFI firmware, nested Intel validation, paused receivers, durable ownership and explicit reconciliation. AMD nested live migration, passthrough and automatic failover remain excluded. Recovery arming after normal completion follows the separate recovery prerequisites.
- New successful live moves automatically remove verified obsolete source disks, NVRAM and TPM after sessions close. Failed/unresolved copies and blocked cleanup retain their data. No shared-storage service is introduced.
- Requires Unraid 7.3.2 or later. Package downloads are SHA-256 pinned; the verified current TXZ is retained for offline boots. Author: Richard Skinner.
</CHANGES>
EOF
printf '%s' '<FILE Run="/usr/bin/php"><INLINE><![CDATA['
# Readable source, not an embedded archive. This guard must execute BEFORE
# upgradepkg replaces running scripts or the security upgrade revokes old keys.
cat "$ROOT/src/rootfs/usr/local/emhttp/plugins/unmotion/include/process-identity.php"
sed '1,2d' "$ROOT/src/rootfs/usr/local/emhttp/plugins/unmotion/include/process-upgrade.php"
# Pin the target's complete hook graph, not hashes chosen by installed code.
printf '\nfunction unmProcessTargetFenceFiles(): array { return [\n'
for file in etc/libvirt/hooks/qemu.d/50-unmotion-recovery usr/local/emhttp/plugins/unmotion/include/{native-fence,live-native-fence,live-firmware,live-storage,live-zvol-native,live-tpm,xml-canonical}.php; do
  printf "'/%s'=>'%s',\n" "$file" "$(sha256sum "$STAGE/$file" | awk '{print $1}')"
done
printf ']; }\n'
printf '\nfunction unmProcessTargetFenceTransitions(): array { return '
php -r '$v=json_decode(file_get_contents($argv[1]),true,32,JSON_THROW_ON_ERROR);if(!is_array($v))exit(1);var_export($v);' "$ROOT/compatibility/native-fence-transitions.json"
printf '; }\n'
cat <<'EOF'
try { unmProcessPreinstall(); } catch (Throwable $error) { echo 'unMotion update blocked: '.$error->getMessage()."\n"; exit(1); }
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
