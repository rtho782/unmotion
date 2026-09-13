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
- Beta candidate: read-only diagnostic reports for migration and clone jobs.
- Review/edit redacted logs, VM configuration, storage evidence and both hosts' technical specifications.
- Download/copy reports without a GitHub account; open an issue draft without storing GitHub credentials or uploading automatically.
- Optional original paths and bounded current-peer specs; recorded metadata remains available when a peer cannot be contacted.
- Concurrent independent Warm Move preparations with VM/seed locks, storage collision checks and duplicate-request protection.
- Archive verified never-started failed seed records with logs retained, without requiring or deleting VM storage.
- Final migration/cutover serialization and protocol versions are unchanged. GPL-3.0-only.
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
