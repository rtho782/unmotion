# unMotion for Unraid — 0.3.0-beta7

unMotion is a private-beta Unraid VM migration and Warm Move plugin. Both peers should run the same release.

## 0.3.0-beta7 highlights

- Fixes Warm Move final-cutover failure in the transfer progress allocator under Bash `set -u`.
- Initializes the byte-count variable before using it in arithmetic, preventing the `span="$(next_span ...)"` crash seen immediately after the final ZFS snapshot.
- Adds a debug transfer-plan summary showing total bytes and transfer-object counts before data movement begins.
- Retains beta6 conditional ISO handling, beta4 diagnostics, compressed/resumable ZFS send/receive, receive-token Resume, dedicated-dataset image replication, two-pass rsync fallback and peer health monitoring.

## Install / upgrade

Copy the standalone PLG to the Unraid boot device and install it on both hosts:

```bash
cp /path/to/unmotion-0.3.0-beta7.plg /boot/config/plugins/unmotion.plg
plugin install /boot/config/plugins/unmotion.plg
```

Verify:

```bash
cat /usr/local/emhttp/plugins/unmotion/VERSION
```

Expected:

```text
0.3.0-beta7
```

Peer protocol remains version 5; existing beta3 pairings should remain valid.

## Debug logging

Enable **Settings > Diagnostics > Debug logging** before reproducing a problem. Debug entries are written into the normal migration or Warm Move log with a `DEBUG` prefix. Disable it again when troubleshooting is complete if the additional detail is not wanted.

Even with Debug logging disabled, unexpected worker failures record the failing command, Bash line and exit status so failures do not degrade to only `Seed operation failed; see log`.

## Current boundaries

This is still private beta software. PCI passthrough, automatic HA/failover, scheduled RPO replication, native encrypted ZFS replication and live RAM migration remain unsupported. Destination storage cleanup remains deliberately conservative where ownership cannot be proven.
