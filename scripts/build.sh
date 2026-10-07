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
<PLUGIN name="&name;" author="&author;" version="&version;" pluginURL="$PLUGIN_URL" launch="&launch;" min="7.3.2" icon="exchange">
<CHANGES>
### unMotion $VERSION
- Requires Unraid 7.3.2 or later. Successful registered installs/updates prune older cached unMotion TXZ downloads while retaining the verified current package for offline boots.
- Application/release version stays $VERSION; the Plugins tab shows fixed installer version $PLUGIN_VERSION for reliable upgrade ordering.
- No migration, cloning, replication, recovery or protocol changes from 0.4.2. Protocol 7 peers remain compatible.
- Finish active operations and shut down recovery-managed VMs before upgrading. Use unMotion controls to restart managed VMs after checking native-fence readiness.
- Upgrading from 0.4.1 or earlier still requires coordinated protocol-7 maintenance: finish or remove old prepared/partial Warm Moves, pause replication and upgrade both peers together.
- Existing settings, host identities and recovery authority records are retained. Keep verified backups. GPL-3.0-only.
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
