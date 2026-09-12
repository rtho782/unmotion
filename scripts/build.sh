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

find "$STAGE" -type d -exec chmod 0755 {} +
chmod 0755 "$STAGE/etc/rc.d/rc.unmotion" "$STAGE/install/doinst.sh" "$STAGE/usr/local/sbin/"*
find "$STAGE/usr/local/emhttp/plugins/unmotion" -type f -exec chmod 0644 {} +

tar -C "$STAGE" --owner=0 --group=0 -cJf "$DIST/$PKG" .
if tar -tvf "$DIST/$PKG" | awk '$1 ~ /^d/ && $1 != "drwxr-xr-x" {print "Unsafe package directory mode: " $0 > "/dev/stderr"; bad=1} END {exit bad?1:0}'; then :; else
  rm -f "$DIST/$PKG"
  exit 1
fi
MD5="$(md5sum "$DIST/$PKG" | awk '{print $1}')"
SHA="$(sha256sum "$DIST/$PKG" | awk '{print $1}')"
B64="$ROOT/work/payload.b64"
base64 "$DIST/$PKG" > "$B64"

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
<!ENTITY payload "/tmp/unmotion-&version;.txz.b64">
]>
<PLUGIN name="&name;" author="&author;" version="&version;" pluginURL="$PLUGIN_URL" launch="&launch;" min="7.0.0" icon="exchange">
<CHANGES>
### unMotion $VERSION
- Stable 0.4.0 release of the tested beta4 feature set; GPL-3.0-only.
- Stable update channel; plugin version includes -stable for Unraid upgrade ordering.
- Resolve manually recovered migrations and finish their original source policy safely.
- Verify ISO contents before reuse and after transfer.
- Map byte-identical firmware and variable templates to valid destination paths.
- Share software TPM discovery and validate exact host-state evidence before cleanup.
- Support owned custom NVRAM for cloning and capability-gated replication/recovery.
- Add a concise description to the Unraid Plugins page; preserve the five-minute source-deletion delay.
</CHANGES>
<FILE Name="&payload;"><INLINE>
EOF
cat "$B64"
cat <<EOF
</INLINE></FILE>
<FILE Run="/bin/bash"><INLINE>
set -e
mkdir -p "&plgdir;/packages"
TMP="&package;.tmp.\$\$"
base64 -d "&payload;" > "\$TMP"
echo "$MD5  \$TMP" | md5sum -c -
mv -f "\$TMP" "&package;"
chmod 600 "&package;"
/sbin/upgradepkg --install-new "&package;"
/etc/rc.d/rc.unmotion restart || true
rm -f "&payload;"
</INLINE></FILE>
<FILE Run="/bin/bash" Method="remove"><INLINE>
/etc/rc.d/rc.unmotion stop || true
/usr/local/sbin/unmotion-cleanup || true
/sbin/removepkg unmotion 2>/dev/null || true
rm -rf /usr/local/emhttp/plugins/unmotion /usr/local/sbin/unmotion-* /etc/rc.d/rc.unmotion
rm -rf "&plgdir;"
rm -f "&payload;"
</INLINE></FILE>
<!-- Embedded package SHA-256: $SHA -->
</PLUGIN>
EOF
} > "$DIST/$PLG"

sha256sum "$DIST/$PKG" "$DIST/$PLG"
