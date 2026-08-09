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
./scripts/build.sh 0.3.1-RC2
```

The script stages `src/rootfs`, applies package permissions, creates a Slackware-style TXZ and embeds it into a PLG under `dist/`. For a new release, update the `VERSION` file and release notes first, then pass the matching version.

## Test deployment

```bash
scp dist/unmotion-<version>.plg root@UNRAID-DEV01:/tmp/unmotion-<version>.plg
ssh root@UNRAID-DEV01 'plugin install /tmp/unmotion-<version>.plg forced && cp /tmp/unmotion-<version>.plg /boot/config/plugins/unmotion.plg && rm -f /boot/config/plugins/unmotion-<version>.plg /var/log/plugins/unmotion-<version>.plg'
```

Repeat for `UNRAID-DEV02`, verify the installed version, restart the plugin and test discovery/pairing before migration tests. The package filename uses a lowercase normalized version token internally (for example, `_rc1`) so Unraid orders it after `_beta7`; the displayed plugin version retains its release spelling.

## Release discipline

1. Preserve the stable installed name `unmotion.plg`.
2. Update both package `VERSION` and PLG changes.
3. Run static verification.
4. Install on both disposable peers.
5. Test cold and Warm Move paths, including recovery/failure cases.
6. Record SHA-256 hashes and create a Git tag only after verification.
