# unMotion

Stable release: **[0.4.1](https://github.com/rtho782/unmotion/releases/tag/0.4.1)**. Both update feeds now offer this stable release; installing it selects future stable updates.

Includes [diagnostic problem reports](docs/DIAGNOSTIC-REPORTS.md), [concurrent warm preparations and failed-record archival](docs/CONCURRENT-WARM-PREPARATION.md), and [parallel independent cutovers with phase-aware preflight](docs/WARNINGS-AND-PREFLIGHT.md). Install 0.4.1 on both peers for parallel cutover support. Operations needing exclusive access wait cancellably; same-VM and overlapping-storage conflicts remain blocked.

unMotion is an Unraid plugin for cloning virtual machines, moving them between paired hosts, and maintaining scheduled ZFS replicas with guarded coordinated manual recovery.

> Keep verified backups. Migration and recovery change VM definitions and storage. Test your workloads before relying on these operations; a successful preflight is not a substitute for a recovery plan.

Author: Richard Skinner. Copyright (C) 2026 Richard Skinner.
Licensed under **GPL-3.0-only**, not GPLv3-or-later. See [LICENSE](LICENSE). Software is provided without warranty under the licence terms.

## Features

- Reciprocal host discovery and SSH pairing.
- Cold migration of powered-off VMs and prepared **Warm Move**, which seeds while running but requires a powered-off final cutover.
- Native ZFS send/receive for zvols and dedicated VM datasets, with sparse rsync fallback for ordinary/shared image files.
- Resumable interrupted ZFS receives, destination conflict checks and guarded source cleanup.
- UEFI NVRAM and software TPM transfer, content-verified firmware mapping, and ISO copy/map/omit handling with checksum validation.
- Same-host full-copy cloning with independent disks, new domain UUIDs and NIC MAC addresses, and disabled autostart.
- Optional Ubuntu Guest Agent customization to reset guest identity and replace inherited static networking before enabling cloned NICs.
- Scheduled replication with RPO choices from five minutes to 24 hours, configurable retention, Guest Agent consistency evidence and opportunistic powered-off TPM checkpoints.
- Guarded coordinated manual recovery, authenticated peer controls and managed-autostart fencing.
- Concurrent independent Warm Move preparation/cutover with cancellable waiting for exclusive operations.
- Reviewable diagnostic reports with logs, VM/storage details and both hosts' specifications; download/copy or open a user-submitted GitHub issue draft.

### Storage and recovery requirements

Replication requires dedicated ZFS zvols or datasets. Shared datasets, non-ZFS storage, encrypted datasets and qcow2 backing chains are not supported for replication. Use ZFS Master or another storage tool to prepare an appropriate layout; unMotion does not convert it.

Retained replica storage remains inert. Manual recovery uses separate activation-owned clones, requires eligible Guest Agent evidence, and requires a reachable, fenced source. Only one recovery destination may be armed for a VM. Arming disables native Unraid autostart; unMotion manages starts until recovery authority is safely reconciled.

While recovery is armed or unreconciled, conflicting migration, clone and replication-policy changes are blocked. Retention can preserve an additional eligible point when the newest point is storage-only.

**Not supported:** live migration, automatic failover, unreachable-source recovery, quorum/witness voting, alternate TPM checkpoint selection or failback transfer. Cold failback is preflight-only.

### Firmware and manually repaired migrations

Custom UEFI variables files may be transferred when ownership is provable and the path can be mapped safely. Firmware loaders/templates are mapped only when size and SHA-256 match; this does not upgrade firmware or convert Secure Boot configuration.

If destination startup fails after transfer, repair and start it manually, then use **Resolve migration**. unMotion rechecks both hosts and finishes the originally selected source policy: retain and rename, unregister while retaining storage, or validated deletion after the five-minute observation period. It does not retransfer disks, overwrite repaired destination XML, or start/stop either VM. Missing ownership evidence, uncertain VM state, unreachable hosts or changed writable disk identities block resolution.

## Installation

Requires Unraid 7.0.0 or later. Install matching unMotion versions on both peers.

Download the [stable unmotion.plg](https://raw.githubusercontent.com/rtho782/unmotion/plugin-stable/unmotion.plg) and use Unraid's **Plugins → Install Plugin**, or save it as /tmp/unmotion.plg and run as root:

```bash
plugin install /tmp/unmotion.plg
cat /usr/local/emhttp/plugins/unmotion/VERSION
```

The application reports **0.4.1**. The Plugins tab reports **0.4.1-stable** for Unraid's version ordering. Preserve the installed filename **unmotion.plg** to avoid duplicate plugin entries.

Pair hosts from **Settings → unMotion**, then test connectivity before migrating a VM.

### Updates

Use **Plugins → Check for Updates → Update**, then refresh the unMotion page. Stable installations follow the **plugin-stable** feed; prerelease testing requires explicit opt-in. Updates preserve settings, pairings and job state.

See [plugin update documentation](docs/PLUGIN-UPDATES.md) for channel maintenance.

### Uninstall

```bash
plugin remove unmotion.plg
```

The removal hook stops unMotion, runs its cleanup helper, removes the package and deletes plugin settings. Review your recovery configuration and retain necessary backups before uninstalling.

## Documentation

- [Architecture](docs/ARCHITECTURE.md)
- [Cloning](docs/CLONING.md)
- [UEFI variables migration](docs/NVRAM-MIGRATION.md)
- [Migration resolution](docs/MIGRATION-RESOLUTION.md)
- [Scheduled replication](docs/REPLICATION.md)
- [Recovery and fencing](docs/RECOVERY.md)
- [Verification](VERIFICATION.md)

## Development

The package root is src/rootfs. Build and verification helpers are in scripts; automated regressions are in tests. Read [AGENTS.md](AGENTS.md) and [development guidance](docs/DEVELOPMENT.md) before changing behavior.

Build on Linux/Unraid with PHP CLI, Bash, Node.js, tar, xz, base64 and checksum tools:

```bash
./scripts/verify.sh
./scripts/build.sh
```

Packages are written to dist. Verify downloaded release checksums; archive metadata can make independent builds differ byte-for-byte.

Use **Report a problem** on a migration or clone job to review and download/copy diagnostics, or open a [GitHub issue](https://github.com/rtho782/unmotion/issues) draft and attach the reviewed report yourself. Nothing is uploaded automatically; check the report before sharing publicly.
