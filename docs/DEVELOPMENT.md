# Development and release workflow

## Prerequisites

Use a Linux/Unraid development environment with Bash, PHP CLI, Node.js (for JavaScript parse checks), `tar`, `xz`, `base64`, `md5sum` and `sha256sum`. Live integration tests additionally need two disposable Unraid hosts with SSH, libvirt, ZFS and test VMs.

## Local checks

```bash
./scripts/verify.sh
```

The script verifies the recovered artifacts, confirms that the PLG payload equals the retained TXZ, compares the TXZ contents with `src/rootfs`, checks version/protocol invariants and runs available syntax checks.

## Build

```bash
./scripts/build.sh 0.3.0-beta7
```

The script stages `src/rootfs`, applies package permissions, creates a Slackware-style TXZ and embeds it into a PLG under `dist/`. For a new release, update the `VERSION` file and release notes first, then pass the matching version.

## Test deployment

```bash
scp dist/unmotion-<version>.plg root@UNRAID-DEV01:/tmp/unmotion.plg
ssh root@UNRAID-DEV01 'cp /tmp/unmotion.plg /boot/config/plugins/unmotion.plg && plugin install /boot/config/plugins/unmotion.plg'
```

Repeat for `UNRAID-DEV02`, verify the installed version, restart the plugin and test discovery/pairing before migration tests.

## Release discipline

1. Preserve the stable installed name `unmotion.plg`.
2. Update both package `VERSION` and PLG changes.
3. Run static verification.
4. Install on both disposable peers.
5. Test cold and Warm Move paths, including recovery/failure cases.
6. Record SHA-256 hashes and create a Git tag only after verification.

