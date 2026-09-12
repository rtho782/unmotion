# Changelog

## 0.4.0 — 2026-09-12

Author: Richard Skinner

### VM cloning and migration

- Same-host full-copy cloning with independent storage, new virtual hardware identities and optional Ubuntu Guest Agent customization.
- Cold migration and prepared Warm Move between paired Unraid hosts, with a powered-off final cutover.
- Native ZFS transfers for zvols and isolated VM datasets, sparse-file fallback for shared storage, resumable receives and guarded source cleanup.
- UEFI NVRAM and software TPM state handling, verified firmware-path mapping and ISO content checks.
- Resolve migration after manual destination repair/start, completing the selected retain-and-rename, unregister or validated-delete source policy.

### Replication and coordinated manual recovery

- Scheduled replication of dedicated ZFS storage, configurable recovery-point objectives and retention.
- Guest Agent consistency evidence, content-addressed host-state checkpoints and opportunistic powered-off TPM capture.
- Authenticated recovery coordination, managed-autostart fencing and manual activation using separate recovery-owned storage.
- Replica removal and cold-failback preflight without implicitly restoring source authority.

### Distribution

- GPL-3.0-only licensing, Copyright (C) 2026 Richard Skinner.
- Stable and beta update channels with a stable installed descriptor named unmotion.plg.
- Community Applications listing metadata and an in-page plugin description.

### Boundaries

Live migration, automatic failover, recovery from an unreachable source, witness voting, alternate TPM checkpoint selection and failback transfer are not supported. Replication requires dedicated ZFS storage. Keep verified backups.
