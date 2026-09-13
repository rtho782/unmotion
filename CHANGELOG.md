# Changelog

## 0.4.1-beta2 — 2026-09-13

- Remove redundant TPM preparation/cutover and ordinary clone confirmations; retain data-deletion, recovery-ownership and genuine consistency warnings.
- Present supported transfer fallbacks as neutral details, and show preparation warnings inline without a second confirmation.
- Make failback preflight, exact-claim renewal and hold-acknowledgement retry single-click actions with unchanged backend validation.
- Distinguish archive-only failed records from prepared-storage deletion. Reject stale archive-only requests without falling through to storage cleanup.
- Separate preparation/update resource guidance from mandatory cold/cutover startup checks. Scope destination health to proven transfer dependencies; unknown dependencies remain conservative.
- Expire timestamped OOM warnings after one hour and remove obsolete release-specific wording from runtime messages.
- Disable prepared-copy controls during cutover, including queued and attention-required jobs; serialize admission and reject conflicting seed mutations on the server.
- Hide optical-media choices for VMs without attached ISOs, with an explicit no-copy default.
- Fingerprint the main browser script so same-version candidate updates refresh their controls correctly.
- Allow independent Warm Move cutovers to overlap between capable beta2 peers, with retained-storage claims and aggregate pending RAM reservations. Serialize only the destination start check/start section.
- Cold/overwrite/shared-ISO-copy and older-peer operations wait cancellably for exclusive access instead of failing on host-wide lock contention.
- No protocol-version changes; partial receives, snapshot ownership, source cleanup and replication/recovery safeguards are retained.

## 0.4.1-beta1 — 2026-09-13

- Add read-only diagnostic reports to migration and clone jobs, including running/stuck jobs.
- Include selected logs, VM/storage metadata and source/destination host specifications with explicit freshness and availability labels.
- Add report-local aliases, mandatory secret filtering, optional original paths, editable preview and explicit public-sharing review.
- Download/copy reports without a GitHub account, or open a generic GitHub issue draft for user-controlled submission.
- Allow independent Warm Move preparations to transfer concurrently, with per-VM/per-seed exclusion and source-side storage reservations. Reject conflicting requests before rewriting active records and prevent launcher status races.
- Allow removal of verified never-started failed seeds by archiving their records and logs locally, without requiring or deleting VM storage. Partial/uncertain transfers retain normal cleanup safeguards.
- Publish on the beta track only; stable remains 0.4.0. No protocol-version changes. Final migration/cutover serialization is unchanged.

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
