# unMotion

unMotion is an experimental Unraid plugin for cloning libvirt virtual machines, moving them between paired Unraid hosts, and maintaining scheduled ZFS replicas on a standby peer. The current development release is `0.4.0-beta1`; the recovered, hash-verified `0.3.0-beta7` baseline remains preserved under `release/0.3.0-beta7/`.

> **Beta software:** migrations change VM definitions and storage. Use disposable test hosts and verified backups. Do not treat a successful preflight as a substitute for a recovery plan.

## What beta1 supports

- Peer discovery and reciprocal pairing over SSH (protocol version 5).
- Cold migration of powered-off VMs.
- Prepared **Warm Move** copies with a final powered-off cutover.
- Native ZFS send/receive for zvols and dedicated per-VM datasets.
- Sparse `rsync` fallback for ordinary/shared image files.
- Transfer of libvirt XML, UEFI NVRAM and software TPM state where applicable.
- Destination mapping and preflight checks for storage, bridges, USB devices and conflicts.
- Resumable interrupted ZFS receives and prepared-copy cleanup.
- Cold same-host full-copy cloning for zvols, dedicated ZFS datasets, raw images and qcow2 images.
- New KVM UUID, genid and NIC MAC addresses for clones, with autostart disabled.
- Ubuntu Guest Agent customization that resets guest identity and replaces Netplan with DHCP before enabling cloned NIC links.
- Scheduled full and incremental replication to a paired beta1 host for zvols and raw/qcow2 images in strictly dedicated ZFS datasets.
- RPO notches of 5, 15 and 30 minutes, then 1, 2, 4, 6, 8, 12 and 24 hours.
- Up to 24 destination recovery points selected from UTC-aligned buckets across the latest 24 hours, within the limits imposed by the selected RPO.
- Resumable interrupted receives, exact snapshot-GUID verification, QEMU Guest Agent consistency/network evidence, and TPM/NVRAM checkpoint metadata.
- Inert destination replica inventory: replica storage is not mounted, exposed as a zvol device, defined in libvirt or started.

Replication deliberately excludes shared datasets, non-ZFS storage, encrypted datasets and qcow2 backing chains. unMotion does not convert those layouts; use the ZFS Master plugin or another storage tool before enabling replication.

`0.4.0-beta1` does **not** activate a replica or implement automatic failover, quorum/witness checks, managed autostart, split-brain prevention, or failback. Those operations require a later guarded recovery protocol. A recovery point without verified Guest Agent evidence is storage-only and will not be eligible for that future activation path.

## Repository layout

```text
src/rootfs/                 Current 0.4.0-beta1 package source, derived from beta7
release/0.3.0-beta7/        Recovered, hash-verified PLG and TXZ
scripts/                    New reproducible build/verification helpers
tests/                      Static regression checks
docs/                       Architecture, development and recovery notes
AGENTS.md                   Durable rules for Codex and contributors
```

The beta7 baseline was recovered, not recreated, and was cross-checked against the separately published source tarball. RC1 and later releases contain explicitly documented post-recovery changes. See [docs/PROVENANCE.md](docs/PROVENANCE.md).

## Install 0.4.0-beta1

Build beta1, copy `dist/unmotion-0.4.0-beta1.plg` to a temporary path on the Unraid host, then run as `root`:

```bash
plugin install /tmp/unmotion-0.4.0-beta1.plg forced
cp /tmp/unmotion-0.4.0-beta1.plg /boot/config/plugins/unmotion.plg
rm -f /boot/config/plugins/unmotion-0.4.0-beta1.plg \
      /var/log/plugins/unmotion-0.4.0-beta1.plg
cat /usr/local/emhttp/plugins/unmotion/VERSION
```

The final command must print `0.4.0-beta1`. The exact versioned descriptor cleanup keeps the required stable `unmotion.plg` identity and prevents a duplicate Plugins-tab entry; it does not remove plugin state.

Install the same version on both peers. Pair hosts from **Settings → unMotion**, then test connectivity before attempting a migration.

## Uninstall

```bash
plugin remove unmotion.plg
```

The plugin removal hook stops the service, runs its cleanup helper, removes the Slackware package and deletes plugin files/settings.

## Build from source

Build on Unraid or Slackware with Bash, `tar`, `xz`, `base64`, `md5sum` and `sha256sum`:

```bash
./scripts/build.sh
./scripts/verify.sh
```

Outputs are written to `dist/`. The build creates a new package from the source tree; it is not expected to reproduce the historical TXZ byte-for-byte because archive metadata and compression can differ. Functional source equality is checked separately.

## Development and testing

Read [AGENTS.md](AGENTS.md) before changing behavior. The minimum safe workflow is documented in [docs/DEVELOPMENT.md](docs/DEVELOPMENT.md) and the proposed isolated lab in [docs/TEST-ENVIRONMENT.md](docs/TEST-ENVIRONMENT.md).

The recovered baseline provenance remains independently verifiable. Development releases are exercised on the disposable `UNRAID-DEV01`/`02` lab, including interrupted receives and outer-VM power loss. Clone-specific behavior is documented in [docs/CLONING.md](docs/CLONING.md); scheduled replication and its beta1 safety boundary are documented in [docs/REPLICATION.md](docs/REPLICATION.md).
