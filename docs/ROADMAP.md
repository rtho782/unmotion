# Feature backlog

Author: Richard Skinner.

This backlog distinguishes accepted work, proposals and unpublished changes. Unless explicitly marked otherwise, entries are not implemented features or release commitments. Current published capabilities are documented in the [README](../README.md).

## Plugin conventions and release housekeeping

Reviewed 2026-10-04. These priorities do not imply that Community Apps requires every item or that any item caused a moderation decision. No host cleanup, feed update or release publication is authorized by recording this backlog.

| Item | Priority | Status / next step |
| --- | --- | --- |
| Prune superseded TXZ package downloads | Completed in 0.4.3 | Native post-install hook prunes older downloads after successful registration, retaining the verified current package for offline boots. |
| Future-proof installer version ordering | Completed in 0.4.3 | Fixed UTC date/sequence prefixes the installer comparison version; public semantic versions stay familiar. |
| Support and documentation links in the installed plugin | Medium | Add PLG `support` metadata and links in the installed description. Link a dedicated Unraid support thread if one is established; GitHub issues remain a valid support destination. |
| Align the minimum Unraid version with the lab | Completed in 0.4.3; older-version testing is low priority / not planned | Requires Unraid 7.3.2. Historical 0.4.2 assets remain unchanged; no extra servers are planned for older versions. |
| Separate uninstall from explicit settings purge | Low | Design retention of settings/history separately from removal, including credential revocation and recovery reconciliation. Current uninstall behaviour is unchanged. |
| App/profile icon and screenshots | Low | Supply a dedicated icon URL in the CA template/profile and useful screenshots. Presentation work, not a confirmed submission blocker. |

### Package-cache cleanup acceptance criteria

- Remove only superseded, recognized unMotion TXZ package files directly inside `/boot/config/plugins/unmotion/packages/`; never recursively delete directories or follow symlinks.
- Retain the verified package for the successfully installed release so subsequent boots do not require a download. Do not prune before download verification, package installation and service startup succeed; protect the package named by the currently registered descriptor until the upgrade is committed.
- Leave settings, credentials, job logs, VM disks, snapshots, partial receives, unrelated archives and staged newer packages untouched. This is not an uninstall or a VM-storage cleanup.
- Test multiple accumulated versions, repeat runs, stable/beta ordering, symlinks/unrelated files, missing or corrupt current packages, failed downloads/installs/restarts and upgrade registration failure. Confirm offline cached boot still works.
- Report which cached downloads were removed. They can be downloaded again from their releases; they are not VM backups. Do not perform manual production cleanup without a separately scoped request.

### Installer versioning (0.4.3 onward)

Application/UI versions, Git tags, release titles and documentation retain semantic versions. Only the PLG's comparison/display version gains a UTC release date and zero-padded publication sequence: release `0.4.3` uses `2026.10.04.01-0.4.3`.

The Unraid Plugins tab shows the longer installer version, including the familiar unMotion version; the plugin UI and GitHub continue to lead with the short semantic version. A date prefix makes the initial transition newer than existing `0.x` descriptors and avoids lexical `0.4.10 < 0.4.9` / `beta10 < beta9` problems.

`RELEASE_ID` binds one fixed identifier to each release, reused on rebuilds and feed copies. `scripts/check-release-feed.php` requires both the installer ID and application version to advance, preventing cross-channel application downgrades. Tests cover historical descriptors, same-day sequencing and double-digit versions. Slackware packages keep their separate semantic-version tokens. Plugin identity and historical assets remain unchanged. See [the release procedure](PLUGIN-UPDATES.md).

### Publication and documentation gate

At every authorized stable release, review the CA template `MinVer`, guide requirements and release availability after verifying the published assets. Keep its `PluginURL` exactly equal to the stable PLG's `pluginURL`; the raw stable branch URL is the catalogue identity, not a per-release commit pin. The 0.4.3 requirement is 7.3.2; documentation of future work must not advance installer feeds ahead of publication.

## Replication retirement and legacy Warm Move cleanup

Status: shipped in **0.5.0-beta6** on 2026-11-10, with qualification and remaining limits in [TESTING-RETIREMENT-BETA6.md](TESTING-RETIREMENT-BETA6.md). The original findings/acceptance requirements below are retained as design context; a general legacy-adoption workflow remains deferred.

A read-only review found a successful protocol-5 preparation created with 0.4.1-beta1 that remained after both peers upgraded to protocol 7. Removal was rejected because the destination had no matching authenticated transport reservation. The old destination copy remained read-only, but the source seed base snapshot was absent after the failed cleanup attempts. The earlier cleanup worker continued to attempt source snapshot deletion even when destination cleanup fails. Stable 0.4.9 still has no stored-replica removal workflow; Beta6 supplies explicit retirement and paired cleanup instead.

- [x] **High: preserve source seed bases when destination cleanup is blocked.** Validate the complete cleanup plan, peer identity, exact ownership and current destination VM/storage state before any storage mutation. Do not release or destroy source seed snapshots after rejected or unconfirmed destination cleanup. Journal acknowledgements and per-object progress so interruption, partial success and lost responses can be reconciled safely on retry. Keep unrelated snapshots, holds, child datasets and resumable receives protected; do not broaden deletion or bypass restricted SSH.
- [x] **High: detect incompatible legacy preparations before upgrade.** Check retained prepared/partial transfers before crossing an ownership or protocol boundary. Block an incompatible upgrade with instructions to finish or explicitly remove those transfers while the old compatible peers remain available. For hosts already upgraded, report the legacy ownership problem before attempting cleanup. Do not infer mutation authority from names, old seed metadata or matching paths, and do not fabricate reservations. Any legacy reconciliation/adoption workflow needs a separate design with fresh proof from both hosts.
- [x] **Medium: distinguish cleanup failure from preparation failure.** Preserve preparation success/history and report the last attempted operation separately. Show actionable states such as cleanup blocked by missing ownership, explain retained source/destination artifacts and any missing incremental base, and avoid offering retries that cannot resolve the blocker. Make the replication-removal limitation explicit instead of implying a pending background task.
- [x] **High: implement explicit replication retirement/removal.** Design the distinction between stopping the schedule, retaining replica data and deleting replica data. Reconcile recovery authority on both hosts, stop new admissions and wait for active work before cleanup. Require authenticated ownership and acknowledgement for exact policy-owned artifacts, preserve incremental bases and partial receives until the applicable cleanup is explicitly authorized and confirmed, and retain enough durable state to retry partial failures. Removing a local row must not orphan remote data or claim that it was deleted. Retaining data must also retain the ownership inventory needed for later inspection and cleanup.

Acceptance requires tests for legacy protocol-5 records after upgrade, matching protocol-7 peers, missing/foreign reservations, unreachable peers, lost cleanup acknowledgements, repeated removal, partial cleanup, held/unrelated snapshots, child datasets, pending receive tokens and armed/unresolved recovery authority. Verify that a rejected destination cleanup leaves source seed bases intact and that UI messages distinguish blocked cleanup from an unsuccessful initial preparation. Include names with spaces such as `Squid Proxy`. Migration/deletion validation requires explicit authorization on the disposable lab hosts and outer-VM snapshots/backups; production cleanup is not authorized by this backlog.

Maintain the preparation, replication and upgrade guides plus the companion Community Apps guide to describe the published workflow and limitations. Existing [upgrade guidance](SECURITY-042.md#upgrade-both-hosts-together), [preparation safeguards](CONCURRENT-WARM-PREPARATION.md) and [recovery boundaries](RECOVERY.md) remain in force.

## Beta6 replication retirement and cleanup

**Published: 0.5.0-beta6**, opt-in beta feed. The following design requirements are implemented, with disposable-lab [results and remaining qualification limits](TESTING-RETIREMENT-BETA6.md). Publication does not authorize production cleanup. A completed retirement permits a fresh, isolated replication cycle for the same VM/destination; old receipts and retained inventories remain separate. Disable resumes the existing chain instead.

Prioritize lifecycle completion over expanding live migration to additional storage formats:

1. **Preserve source bases on failed cleanup.** Fix the existing Warm Move cleanup ordering first: rejected or unconfirmed destination cleanup must not release/destroy source seed bases. Preflight ownership and journal exact object outcomes; make retries safe after partial success or lost acknowledgements.
2. **End replication explicitly.** Retain the existing temporary **Disable** action, and introduce **End replication** with two reviewed choices: retain the destination points, or delete the exact policy-owned replica data. Show the owned datasets/snapshots, partial receives and TPM/NVRAM artifacts affected before confirmation. Never delete the source VM or its writable disks.
3. **Retire both peers safely.** Persist a retirement intent, stop admission from scheduling/manual/lifecycle triggers and settle active work before mutations. Reconcile/disarm recovery authority on both hosts first; a running recovered activation, unresolved grant or uncertain peer blocks completion. Ending an unarmed policy should not require shutting down its ordinary source VM. Source bases can be released only after the relevant destination acknowledgement is durably recorded. A lost response must allow a retry, not silently remove records or fences.
4. **Keep retained replicas visible.** Retire the schedule while retaining a read-only inventory, manifests and ownership records for later inspection or explicit deletion. Retention pruning stops. Kept replicas are not ordinary runnable VMs, and retirement alone grants no recovery authority. Do not silently discard an interrupted receive; retaining it or explicitly aborting/removing it must be part of the reviewed choice. Deletion must account for foreign holds/clones/children and preserve artifacts referenced elsewhere.
5. **Make blocked operations actionable.** Separate preparation success, cleanup progress and cleanup failure. Show retirement states and exact retained artifacts, with retry when meaningful. If a peer is unreachable, leave the schedule stopped and retirement visibly incomplete; do not claim deletion or release recovery fences. Preserve operation logs/history independently of the active policy row.
6. **Guard incompatible upgrades and legacy state.** Detect old prepared/partial transfers before crossing an incompatible protocol/ownership boundary. For already-upgraded protocol-5 seeds without authenticated destination ownership, report the blocker without deleting source bases or fabricating reservations. A general legacy-adoption mechanism remains separate design work.

Implemented through optional protocol-7 capabilities. Peers lacking that capability reject the operation before destructive changes; older unrelated workflows should remain compatible. There is no unrestricted SSH fallback.

Acceptance on DEV01/DEV02 requires current outer-VM snapshots/backups and tests of unused/healthy/disabled/partial policies; keep versus delete; source VM left running for unarmed retirement; running recovered copies; stale/in-flight authority; scheduler/lifecycle races; offline peers; lost acknowledgements; reboot during retirement; repeated requests; exact cleanup with held/unrelated snapshots, clones and child datasets; retained receive tokens; legacy protocol-5 records; mixed-capability peers; and names such as `Squid Proxy`. Run the full isolated suite and relevant migration/recovery regressions, then update source and Community Apps guidance for the actual implemented scope.

Defer zvol/multiple-disk live migration, HTTPS transport and recovery arming after live hand-back unless separately brought into scope. A one-off live migration is not continuous replication: Beta5 already provides powered-off **Return to ordinary workflows** for settled live-managed VMs. Review that control's discoverability separately rather than conflating its ownership ledger with replication retirement.

## Live migration follow-up

The current beta retains Beta5's pinned new-VM CPU default/native editor integration, UEFI without TPM, powered-off ordinary hand-back, readiness/progress and browser-session handling. See the [qualification record](TESTING-LIVE-BETA5.md).

Later work includes zvol/multiple-disk live storage and a reviewed ownership handover from formerly live-managed VMs to coordinated recovery. Replication-only configuration after hand-back does not yet allow recovery arming. TPM live migration, AMD nested live migration, passthrough and automatic failover are not implied by this backlog.

Beta5 separates provisional online file-seed verification from the mandatory powered-off final transfer. It checks the received length against rsync's file list, tolerates subsequent guest disk growth, and forces the final mutable-image delta even when file size/timestamps match. A provisional copy is not a bootable recovery point.

## Expand the peer API and reduce SSH round trips

Status: accepted for future work. Target release: unassigned.

Version 0.4.5 implements a display-only inventory cache and an optional `inventory-view` operation over the existing paired transport (`inventoryViewVersion: 1`). Basic rows precede expensive details; XML is reused within the scan. The old fresh `inventory` operation and safety checks remain unchanged. HTTPS, batched migration preflight and connection reuse below remain future work.

Replace remaining small, command-shaped remote queries with named unMotion operations. The receiving host should own its local inspection and validation, returning structured results instead of making the source orchestrate individual commands.

Planned stages:

- [ ] Add named, batched agent requests for destination inspection: VM conflicts/state, storage capacity, dataset properties and relevant file metadata. Reuse the existing restricted SSH transport initially.
- [ ] Measure connection count and preflight/status latency; evaluate safe SSH connection reuse where beneficial. Cache only information that can tolerate staleness, never start authority or safety-critical state.
- [ ] Design an optional authenticated HTTPS peer API for inventory, health, preflight, job management and recovery coordination. Keep restricted SSH for bulk ZFS/rsync transfers initially; removing SSH entirely is a separate design decision.

Design requirements:

- Keep peer authentication, exact VM/storage ownership, protocol negotiation, bounded requests and structured errors independent of the transport.
- An HTTPS peer API must use verified TLS and dedicated machine-to-machine authentication, with credential revocation and replay protection. Evaluate mutual TLS; do not reuse browser login cookies or weaken Unraid CSRF checks.
- Revalidate safety-critical conditions at mutation time. Preserve locking, recovery authority, single-use start permits, resumable transfers and retry-safe operations; batching alone does not make a multi-step operation atomic.
- Keep the synchronous native pre-start hook entirely local, with no SSH, HTTP or libvirt calls from the hook.
- Retain the root-account assumption for existing SSH operations and do not introduce an unrestricted fallback.

Completion requires before/after measurements, mixed-version rejection/negotiation tests, authentication and stale/replayed-request tests, and migration/replication/recovery regressions on both disposable Unraid hosts. Update user guidance when functionality becomes available; planning alone does not change release feeds or Community Apps capabilities.

Related: [architecture](ARCHITECTURE.md), [protocol-7 security model](SECURITY-042.md), [recovery safety](RECOVERY.md).
