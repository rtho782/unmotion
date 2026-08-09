#!/bin/bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SEED="$ROOT/src/rootfs/usr/local/sbin/unmotion-seed-worker"
WORKER="$ROOT/src/rootfs/usr/local/sbin/unmotion-worker"
CLONE_TRANSFORM="$ROOT/src/rootfs/usr/local/sbin/unmotion-clone-transform"

row=$'zvol\tcache/Squid Proxy\t\tunmotion-seed-seed-test-g1\t\t'
IFS=$'\034' read -r kind src dst snap rest <<< "${row//$'\t'/$'\034'}"
[[ "$kind" == zvol ]]
[[ "$src" == 'cache/Squid Proxy' ]]
[[ -z "$dst" ]]
[[ "$snap" == 'unmotion-seed-seed-test-g1' ]]

grep -Fq 'IFS=$'"'"'\034'"'"' read -r kind src dst snap' "$SEED"
grep -Fq 'zfs get -H -o value receive_resume_token $(sq "$dst")' "$SEED"
! grep -F 'remote_resume_token(){' "$SEED" | grep -Fq '|| true'
[[ "$(grep -Fc 'elif zfs list -H -o name $(sq "$parent")' "$SEED")" == 1 ]]
[[ "$(grep -Fc 'elif zfs list -H -o name $(sq "$parent")' "$WORKER")" == 1 ]]
grep -Fq 'if ((WARM_MODE));then args+=(--inplace --no-whole-file);else args+=(--partial-dir=.unmotion-partial);fi' "$WORKER"
grep -Fq 'protect_warm_images(){' "$WORKER"
grep -Fq 'DEST_START_ATTEMPTED == 0' "$WORKER"
grep -Fq 'chmod 0400 $(sq "$dst")' "$WORKER"
[[ "$(grep -Fc 'protect_warm_images; restore_source' "$WORKER")" == 3 ]]

TMP="$(mktemp -d)"
trap 'rm -rf -- "$TMP"' EXIT
cat >"$TMP/source.xml" <<'XML'
<domain type='kvm'><name>Squid Proxy</name><uuid>11111111-1111-4111-8111-111111111111</uuid><genid>22222222-2222-4222-8222-222222222222</genid><os><nvram>/etc/libvirt/qemu/nvram/source_VARS.fd</nvram></os><devices><disk type='file' device='disk'><source file='/mnt/pool/domains/Squid Proxy/vdisk1.qcow2'/></disk><interface type='bridge'><mac address='52:54:00:00:00:01'/><source bridge='br0'/><target dev='vnet7'/></interface><hostdev mode='subsystem' type='usb'><source><vendor id='0x1234'/><product id='0xabcd'/></source></hostdev><graphics type='vnc' port='5907'/></devices></domain>
XML
cat >"$TMP/plan.json" <<'JSON'
{"clone":{"name":"Squid Proxy - Clone","uuid":"aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa","genid":"bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb","macs":["52:54:00:12:34:56"]},"pathMap":{"/mnt/pool/domains/Squid Proxy/vdisk1.qcow2":"/mnt/pool/domains/Squid Proxy - Clone/vdisk1.qcow2","/etc/libvirt/qemu/nvram/source_VARS.fd":"/etc/libvirt/qemu/nvram/aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa_VARS.fd"}}
JSON
php "$CLONE_TRANSFORM" "$TMP/source.xml" "$TMP/disconnected.xml" "$TMP/plan.json" disconnected
grep -Fq '<name>Squid Proxy - Clone</name>' "$TMP/disconnected.xml"
grep -Fq '<uuid>aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa</uuid>' "$TMP/disconnected.xml"
grep -Fq 'address="52:54:00:12:34:56"' "$TMP/disconnected.xml"
grep -Fq 'state="down"' "$TMP/disconnected.xml"
grep -Fq 'Squid Proxy - Clone/vdisk1.qcow2' "$TMP/disconnected.xml"
! grep -Fq '<hostdev' "$TMP/disconnected.xml"
! grep -Fq 'vnet7' "$TMP/disconnected.xml"
php "$CLONE_TRANSFORM" "$TMP/source.xml" "$TMP/connected.xml" "$TMP/plan.json" connected
! grep -Fq '<link' "$TMP/connected.xml"

echo 'Shell RC2 behavior regressions passed.'
