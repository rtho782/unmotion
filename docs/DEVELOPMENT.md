# Development and release workflow

## Prerequisites

Use a Linux/Unraid development environment with Bash, PHP CLI, Node.js (for JavaScript parse checks), `tar`, `xz` and `sha256sum`. Verification of historical fixtures also uses `base64`. Live integration tests additionally need two disposable Unraid hosts with SSH, libvirt, ZFS and test VMs. Run the full suite with privileges on a disposable development host: some regressions use isolated fixtures under `/mnt` and lock files under `/var/run`.

## Local checks

```bash
./scripts/verify.sh
```

The script verifies regression fixtures, checks current version/protocol invariants, runs behavioral regressions and executes all available syntax checks.

## Build

```bash
./scripts/build.sh 0.4.2
```

The script stages `src/rootfs`, applies package permissions and creates a Slackware-style TXZ and a small PLG under `dist/`. The PLG references that exact package on the matching GitHub release tag and supplies its SHA-256 for Unraid's plugin manager to verify. It does not embed the archive. For a new release, update the `VERSION` file and release notes first, then pass the matching version.

`tests/package-regressions.sh` builds stable and beta descriptors and checks XML, archive hashes, channel selection and shell syntax. On Unraid it also exercises the native FILE processor with intercepted downloads and commands, covering cache reuse, corruption, unavailable downloads and installer failure without installing the package.

## Test deployment

```bash
scp dist/unmotion-<version>.plg root@UNRAID-DEV01:/tmp/unmotion.plg
# Before publication, copy the exact candidate TXZ to the cache path named in
# the PLG. Its SHA-256 must match; never advance a live feed to an absent asset.
ssh root@UNRAID-DEV01 'mkdir -p /boot/config/plugins/unmotion/packages'
scp dist/unmotion-<package-token>-noarch-1.txz root@UNRAID-DEV01:/boot/config/plugins/unmotion/packages/
ssh root@UNRAID-DEV01 'plugin install /tmp/unmotion.plg'
```

Repeat for `UNRAID-DEV02`, verify the installed version and test discovery/pairing before migration tests. Stable releases use a `-stable` descriptor suffix and `_stable` package token for upgrade ordering; the application version is unchanged. See `docs/PLUGIN-UPDATES.md`. The builder also includes the root GPL-3.0-only LICENSE in the installed plugin.

For 0.4.2 and later, follow [protocol-7 upgrade requirements](SECURITY-042.md). Keep both mixed-version directions in the rejection test matrix; retaining schema-1 recovery records must not weaken fencing or re-enable native autostart.

## Release discipline

1. Preserve the stable installed name `unmotion.plg`.
2. Update both package `VERSION` and PLG changes.
3. Run static verification.
4. Install on both disposable peers.
5. Test cold and Warm Move paths, including recovery/failure cases.
6. For replication releases, test interrupted full/incremental receives, exact GUID equality, destination inertness, UTC retention, QGA-free and QGA-quiesced points, shared-storage rejection and exact cleanup.
7. Record SHA-256 hashes and create a Git tag only after verification. Publish and verify the exact tagged TXZ release asset before publishing its PLG to the live feeds or updating the CA template. Do not rebuild a package after computing the descriptor hash.
8. As part of authorized release publication, update and publish the [Community Apps user guide](https://github.com/rtho782/unmotion-community-apps), then verify its release/channel information and installer links. Beta publication must not change the Apps template's stable installer selection.

## Documentation checklist

Follow [User documentation maintenance](../AGENTS.md#user-documentation-maintenance) for every user-facing feature, changed requirement/limitation and release. Update the source documentation and the companion Community Apps guide as part of the same task; the guide describes how the product works today, not its version history.

- Cover affected features, instructions, prerequisites, limitations and support guidance.
- Label unpublished features accurately. At publication, refresh the stable/beta/candidate availability table and feature labels from the actual release and feed.
- Check whether the Apps Overview, ReadMe link or repository profile also needs updating. Preserve the stable manifest pin during beta/documentation-only work.
- Validate modified XML and links, and verify published files after pushing. If publication is out of scope or blocked, identify the prepared documentation and remaining publication work in the handoff.
