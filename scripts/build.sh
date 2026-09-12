#!/bin/bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VERSION="${1:-$(tr -d '\r\n' < "$ROOT/src/rootfs/usr/local/emhttp/plugins/unmotion/VERSION")}"
AUTHOR="Richard Skinner"
PLUGIN_URL="https://raw.githubusercontent.com/rtho782/unmotion/codex/plugin-beta/unmotion.plg"
SAFE_VERSION="${VERSION//-/_}"
# Slackware/Unraid compares package versions bytewise enough that an uppercase
# _RC1 sorts before the recovered lowercase _beta7 package token.  Keep the
# display/plugin version unchanged, but normalize the package token so RC1 is
# recognized as the upgrade it is.
SAFE_VERSION="${SAFE_VERSION,,}"
PKG="unmotion-${SAFE_VERSION}-noarch-1.txz"
PLG="unmotion-${VERSION}.plg"
STAGE="$ROOT/work/package-root"
DIST="$ROOT/dist"

rm -rf "$STAGE"
mkdir -p "$STAGE" "$DIST"
cp -a "$ROOT/src/rootfs/." "$STAGE/"

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
<!ENTITY version "$VERSION">
<!ENTITY launch "UnMotion">
<!ENTITY plgdir "/boot/config/plugins/&name;">
<!ENTITY package "&plgdir;/packages/$PKG">
<!ENTITY payload "/tmp/unmotion-&version;.txz.b64">
]>
<PLUGIN name="&name;" author="&author;" version="&version;" pluginURL="$PLUGIN_URL" launch="&launch;" min="7.0.0" icon="exchange">
<CHANGES>
### unMotion $VERSION
- Support custom pool-resident UEFI NVRAM beside a disk in a verified VM-owned directory for cold migration and Warm Move.
- Rewrite the variables-file path with the disk mapping and verify SHA-256 before destination definition.
- Carry dedicated-dataset NVRAM in ZFS transfers; explicitly copy it after shutdown on the sparse file-copy path.
- Reject ambiguous ownership, unsafe paths and existing non-bundled NVRAM destinations during preflight.
- Require beta3 on the destination for custom paths; preserve protocol-5 transport and beta2 recovery behavior.
- Enable Unraid Plugins-tab updates through the published beta release feed.
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
