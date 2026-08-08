# unMotion

unMotion is an experimental Unraid plugin for moving libvirt virtual machines between paired Unraid hosts. This repository preserves the recovered `0.3.0-beta7` release exactly and adds the documentation and build scaffolding needed for continued Git-based development.

> **Beta software:** migrations change VM definitions and storage. Use disposable test hosts and verified backups. Do not treat a successful preflight as a substitute for a recovery plan.

## What beta7 supports

- Peer discovery and reciprocal pairing over SSH (protocol version 5).
- Cold migration of powered-off VMs.
- Prepared **Warm Move** copies with a final powered-off cutover.
- Native ZFS send/receive for zvols and dedicated per-VM datasets.
- Sparse `rsync` fallback for ordinary/shared image files.
- Transfer of libvirt XML, UEFI NVRAM and software TPM state where applicable.
- Destination mapping and preflight checks for storage, bridges, USB devices and conflicts.
- Resumable interrupted ZFS receives and prepared-copy cleanup.

## Repository layout

```text
src/rootfs/                 Exact files extracted from the beta7 TXZ
release/0.3.0-beta7/        Recovered, hash-verified PLG and TXZ
scripts/                    New reproducible build/verification helpers
tests/                      Static regression checks
docs/                       Architecture, development and recovery notes
AGENTS.md                   Durable rules for Codex and contributors
```

The executable beta7 product code is recovered, not recreated, and was cross-checked against the separately published source tarball. See [docs/PROVENANCE.md](docs/PROVENANCE.md).

## Install the recovered beta7 release

Copy `release/0.3.0-beta7/unmotion-0.3.0-beta7.plg` to an Unraid host, then run as `root`:

```bash
cp /path/to/unmotion-0.3.0-beta7.plg /boot/config/plugins/unmotion.plg
plugin install /boot/config/plugins/unmotion.plg
cat /usr/local/emhttp/plugins/unmotion/VERSION
```

The final command must print `0.3.0-beta7`. Use the stable installed filename `unmotion.plg`; versioned `.plg` filenames can create duplicate entries in Unraid's Plugins tab.

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

No live destructive migration was run while reconstructing this repository. Verification here covers artifact provenance, extraction equality, version/protocol invariants and available syntax checks.
