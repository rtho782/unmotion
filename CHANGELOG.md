# Changelog

## 0.4.2 — 2026-10-04

- Restrict managed SSH keys to a forced-command protocol-7 endpoint, binding operations to the authenticated peer, selected VM and admitted storage. Existing matching managed keys are restricted in place; unrelated administrator keys are preserved.
- Replace remote shell execution with typed, validated operations, bounded ZFS controls and confined file transfers. Arbitrary shells, forwarding, unrestricted rsync servers and unsafe VM XML/archive targets are rejected.
- Require both peers to use protocol 7. Existing pairing identities/settings and durable recovery authority remain; there is no protocol-5/6 transfer fallback. Legacy prepared/partial data without authenticated ownership is retained and blocked from implicit adoption.
- Verify boot ID, process start time, executable and arguments before signalling jobs or service daemons; reject stale/reused PID records. Removal aborts if safe stopping or cleanup cannot be established.
- Escape both quote types in dynamic HTML and reject malformed, out-of-range or overflowing peer resource values before shell arithmetic.
- Preserve fully received ZFS data when resuming the same failed job after a later host-state error: require that job's exact snapshot GUID and revalidate destination ownership rather than deleting or blindly adopting an existing dataset.
- Add a native libvirt pre-start hook alongside Unraid's existing hooks. Recovery-managed starts require single-use, authority-bound permits; in-flight source starts block recovery grants. Arming/grants require actual hook-callback proof, and upgrade/removal guards preserve fencing.
- Add a one-off **Start source VM** recovery action with fresh peer authorization, without changing the managed-autostart preference.
- Correct managed-start autostart parsing so large libvirt output cannot cause a broken-pipe failure; distinguish native autostart from Unraid's one-time autostart field.
- Publish the tested security and native-fencing changes as stable 0.4.2. Both installer feeds offer `0.4.2-stable`; installing it graduates beta-feed users to stable updates. Upgrade both peers together and complete the documented maintenance prerequisites. Candidate test evidence is retained separately from the final stable artifact checksums.

## 0.4.1 installer packaging — 2026-10-03

- Adopt the Community Apps review's release-asset download in place of the PLG's embedded base64 archive, with native SHA-256 verification and persistent package caching.
- Refresh the stable/graduation feeds and CA installer selection using the exact existing 0.4.1 TXZ. Application code, package bytes, versions and historical release assets are unchanged; existing installations need no reinstall.
- Add packaging regressions for stable/beta URLs, checksums, cached boots, corrupt/unavailable downloads and package-install failure. Document publish order and reciprocal root pairing access.

## 0.4.1 — 2026-09-13

- Promote the tested 0.4.1 beta functionality to stable, including the compact Plugins-tab heading. No migration or recovery algorithm changes from the refreshed beta2 build.
- Add reviewable diagnostic reports containing selected logs, VM/storage metadata and available technical specifications for both hosts, with download/copy and user-controlled GitHub issue submission.
- Support concurrent independent Warm Move preparations and cutovers, with same-VM/storage guards, destination startup resource checks and cancellable waiting for exclusive or older-peer operations.
- Archive verified never-started failed preparation records without deleting storage. Disable prepared-copy actions while a job owns the VM, and omit ISO choices when no ISO is attached.
- Use phase-aware resource and dependency-scoped health checks, with concise notices and unchanged destructive/ownership safeguards.
- Publish 0.4.1-stable through both feeds and update the Community Apps stable installer. Application version is 0.4.1; existing beta installations graduate to stable updates on installation.
- Protocol versions and the five-minute source-deletion validation period remain unchanged.

## 0.4.1-beta2 — 2026-09-13

- Use a compact heading for the Plugins-tab description, matching other Unraid plugins.
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
