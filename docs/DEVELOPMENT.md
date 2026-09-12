# Development and release workflow

## Prerequisites

Use a Linux/Unraid development environment with Bash, PHP CLI, Node.js (for JavaScript parse checks), `tar`, `xz`, `base64`, `md5sum` and `sha256sum`. Live integration tests additionally need two disposable Unraid hosts with SSH, libvirt, ZFS and test VMs.

## Local checks

```bash
./scripts/verify.sh
```

The script verifies the recovered beta7 artifacts independently, checks current version/protocol invariants, runs behavioral regressions and executes all available syntax checks.

## Build

```bash
./scripts/build.sh 0.4.0
```

The script stages `src/rootfs`, applies package permissions, creates a Slackware-style TXZ and embeds it into a PLG under `dist/`. For a new release, update the `VERSION` file and release notes first, then pass the matching version.

## Test deployment

```bash
scp dist/unmotion-<version>.plg root@UNRAID-DEV01:/tmp/unmotion.plg
ssh root@UNRAID-DEV01 'plugin install /tmp/unmotion.plg'
```

Repeat for `UNRAID-DEV02`, verify the installed version and test discovery/pairing before migration tests. Stable releases use a `-stable` descriptor suffix and `_stable` package token for upgrade ordering; the application version is unchanged. See `docs/PLUGIN-UPDATES.md`. The builder also includes the root GPL-3.0-only LICENSE in the installed plugin.

## Release discipline

1. Preserve the stable installed name `unmotion.plg`.
2. Update both package `VERSION` and PLG changes.
3. Run static verification.
4. Install on both disposable peers.
5. Test cold and Warm Move paths, including recovery/failure cases.
6. For replication releases, test interrupted full/incremental receives, exact GUID equality, destination inertness, UTC retention, QGA-free and QGA-quiesced points, shared-storage rejection and exact cleanup.
7. Record SHA-256 hashes and create a Git tag only after verification.
