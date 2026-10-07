#!/bin/bash
# Opt-in, only on the two disposable Unraid labs with their outer backups.
set -euo pipefail
case "$(hostname)" in UNRAID-DEV01) pool=cache;; UNRAID-DEV02) pool=zfspool;; *) echo 'Disposable lab host required' >&2; exit 2;; esac
test_dir=$(cd "$(dirname "$0")" && pwd)
include_file=$(cd "$test_dir/.." && pwd)/src/rootfs/usr/local/emhttp/plugins/unmotion/include/transport.php
probe_dir=$(mktemp -d /tmp/unmotion-beta042-transport.XXXXXX)
probe_root="$pool/unmotion-beta042-transport-${probe_dir##*.}"
zfs list "$probe_root" >/dev/null 2>&1 && { echo 'Probe name already exists'; exit 2; }
zfs create -o mountpoint=none -o canmount=off "$probe_root"
cleanup() {
  # Every target was created by this invocation; no recursion, globs or VM disks.
  for child in valid-fs valid-vol wrong-fs wrong-vol compound-fs resumed source-fs source-vol; do
    target="$probe_root/$child"
    zfs list "$target" >/dev/null 2>&1 || continue
    token=$(zfs get -H -o value receive_resume_token "$target")
    if [[ -n "$token" && "$token" != - ]]; then zfs receive -A "$target"; fi
    for snapshot in s t; do zfs list "$target@$snapshot" >/dev/null 2>&1 && zfs destroy "$target@$snapshot"; done
    zfs list "$target" >/dev/null 2>&1 && zfs destroy "$target"
  done
  zfs destroy "$probe_root"
}
trap cleanup EXIT
zfs create -o mountpoint="$probe_dir/source" -o canmount=on "$probe_root/source-fs"
dd if=/dev/urandom of="$probe_dir/source/payload" bs=1M count=16 status=none
zfs create -V 8M -o volmode=none "$probe_root/source-vol"
zfs snapshot "$probe_root/source-fs@s" "$probe_root/source-vol@s"
zfs send -c "$probe_root/source-fs@s" > "$probe_dir/fs.stream"
zfs send -c "$probe_root/source-vol@s" > "$probe_dir/vol.stream"
zfs send -cp "$probe_root/source-fs@s" > "$probe_dir/compound.stream"
receive() { php "$test_dir/transport-zfs-native-receiver.php" "$include_file" "$1" "$probe_root/$2"; }
receive dataset valid-fs < "$probe_dir/fs.stream"
receive zvol valid-vol < "$probe_dir/vol.stream"
[[ $(zfs get -H -o value readonly "$probe_root/valid-fs") == on ]]
[[ $(zfs get -H -o value canmount "$probe_root/valid-fs") == off ]]
[[ $(zfs get -H -o value volmode "$probe_root/valid-vol") == none ]]
[[ $(zfs get -H -o value dedup "$probe_root/valid-fs") == off ]]
[[ $(zfs get -H -o value compression "$probe_root/valid-fs") == lz4 ]]
for row in 'zvol wrong-fs fs' 'dataset wrong-vol vol' 'dataset compound-fs compound'; do
  read -r kind target stream <<< "$row"
  if receive "$kind" "$target" < "$probe_dir/$stream.stream"; then echo "Unsafe stream accepted: $target" >&2; exit 1; fi
  if zfs list "$probe_root/$target" >/dev/null 2>&1; then echo "Rejected stream created storage: $target" >&2; exit 1; fi
done
head -c 1048576 "$probe_dir/fs.stream" > "$probe_dir/partial.stream"
if receive dataset resumed < "$probe_dir/partial.stream"; then echo 'Partial stream unexpectedly completed' >&2; exit 1; fi
token=$(zfs get -H -o value receive_resume_token "$probe_root/resumed")
[[ -n "$token" && "$token" != - ]]
zfs send -t "$token" | receive dataset resumed
[[ $(zfs get -H -o value guid "$probe_root/source-fs@s") == "$(zfs get -H -o value guid "$probe_root/resumed@s")" ]]
[[ $(zfs get -H -o value receive_resume_token "$probe_root/resumed") == - ]]
printf 'incremental update\n' >> "$probe_dir/source/payload"
zfs snapshot "$probe_root/source-fs@t"
zfs send -c -i "$probe_root/source-fs@s" "$probe_root/source-fs@t" | receive dataset valid-fs
[[ $(zfs get -H -o value guid "$probe_root/source-fs@t") == "$(zfs get -H -o value guid "$probe_root/valid-fs@t")" ]]
printf 'Native ZFS transport: typed filesystem, volume, wrong-kind rejection, compound rejection, interrupted resume, incremental GUID and explicit properties passed. Probe=%s\n' "$probe_root"
