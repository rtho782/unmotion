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

### Native performance beta — 0.5.2-beta4

Published on the opt-in beta feed on 2026-11-14 as **0.5.2-beta4**. Stable/Community Apps remains 0.5.0. [Native-mode qualification](NATIVE-TRUSTED-MIGRATION.md) and [host-loss/reboot results](NATIVE-HOST-LOSS-052-QUALIFICATION.md) supersede the initial optional-guard proposal below. Actual receiver-copy loss and post-resume source loss/reconciliation passed; this is not automatic HA or an exhaustive physical-failure matrix.

- **Native trusted handover:** available for new jobs on matching beta peers. Explicit trust on both peers permits temporary administrator-equivalent OpenSSH/libvirt access, without Go forwarding, RPC filtering or an extra application-controlled pause. Persistent MOVING/RECEIVING start fences are prepared before handover; durable completion, source absence/retirement and authenticated ownership bookkeeping follow native resume. Ambiguous failures stay fenced. This is not a per-path security boundary against a compromised administrator. Old guarded jobs are not silently converted.
- **Encryption dropdown:** implemented AES-128-GCM default and AES-256-GCM selection for outgoing SSH, including ordinary workers and live migration. Jobs pin/capture their cipher and fail rather than silently fall back. Both encrypt and authenticate; performance rankings are hardware dependent. ChaCha20/CTR remain deferred, not current options.
- **Earlier handover reductions:** event-driven command completion, independent display-progress sampling and consolidation of same-filesystem authority flushes reduced pause about 35% in the earlier private guarded candidate. That [bounded qualification](LIVE-HANDOVER-PERFORMANCE.md) is historical evidence, not measurement of each layer in the subsequent native redesign. The generic command runner used by ordinary workflows is unchanged.
- **Fully native comparison and integration:** a separate unmanaged diagnostic bypassed Go forwarding, RPC filtering and the extra `--suspend`/application resume together, measuring 0.138/0.168 s event pauses versus 1.419/1.371 s for the earlier guarded candidate. The subsequent integrated private candidate has its own [qualification](NATIVE-TRUSTED-MIGRATION.md#qualification): file/zvol and Windows TPM/BitLocker moves, native start fences, post-resume shutdown/lost-worker reconciliation and both ciphers. These layers changed together; do not assign all gains to one component or bypass existing fences using the earlier unmanaged experiment.
- **Physical-host native evidence:** a separately authorized live relocation of the idle outer lab hypervisor subsequently preserved its OS boot, UEFI/software TPM and Intel VMX across physical hosts. It used native full raw-disk copy, AES-128-GCM and unchanged writeback caching without `--unsafe`: 606.6 s overall, 456 MiB/s whole-connection average and 288 ms event pause. See [qualification limits and licensing recovery](LIVE-HANDOVER-PERFORMANCE.md#subsequent-physical-host-test--outer-lab-hypervisor). This does not qualify running nested guests or a public native-handover mode.
- **Review the writeback gate for fully copied disks:** current unMotion rejects writeback caching, whereas the qualified native libvirt build accepts disks being fully copied without `--unsafe`. Investigate a narrowly specified full-copy exception, preserving flush ordering, native checks, interrupted-copy safety and fresh disk identity. This is a backlog item, not a shipped relaxation; shared-storage and partial-copy paths must not inherit an unqualified exception.
- **Installer descriptor inheritance:** fix and qualify private installation/daemon launch paths so background services cannot inherit open descriptors to scripts on removable/exportable pools. A real lab USB-removal/array-stop exposed two idle daemons holding an earlier private install helper open. Test child descriptors plus normal array stop/start before calling this resolved; the public GUI installer path was not implicated or freshly qualified by this finding.
- **Qualification:** use DEV01 on Unraid 7.3.4 and DEV02 on 7.4.0. Compare equivalent disposable guests with passive migration/lifecycle events, not repeated diagnostic state/statistics polling during cutover. Include cancellation, receiver rejection, process cleanup, TPM-protected boot and existing regression suites. A state query that waits during libvirt teardown is not proof that monitoring caused the wait; do not promise subsecond handover without measured evidence.

This candidate remains private lab work. Implemented does not mean qualified or published; neither plugin feed changes and no production testing is authorized by this roadmap.

### Beta9 stabilization scope

Implemented, lab-qualified and **published as 0.5.0-beta9 on the opt-in beta feed** on 2026-11-11. DEV01 stayed on Unraid 7.3.4 and DEV02 on 7.4.0. This adds reviewed, exact inactive-copy cleanup with owner leases, immutable identity plans and retryable deletion receipts, plus native receive-completion proof before an uncommitted receiver may own a VM. Recorded zvol GUIDs can support older jobs; unrecorded file/state identities are retained rather than adopted. See [workflow](LIVE-CLEANUP-BETA9.md) and [qualification](BETA9-QUALIFICATION.md).

The recorded lab scope passed actual host loss during copy/handover, Linux TPM protected boot/live proof, cleanup/reboot/replay blockers, normal cold/warm and replication regressions, and the guarded package/upgrade path. Qualification also found and fixed import-order ZFS file-identity renumbering; only newly proven dataset/GUID identities may use that fix. Installed description/support-link polish and the companion guide accompany the release. Only the beta feed advances; stable remains 0.4.9 and production updates are left to the owner. Separate physical-host validation is a proposed later test, not permission to alter existing production VMs. HTTPS, broader CPU/passthrough support and recovery authority-system handover remain deferred.

### Beta8 development scope

Implemented, lab-qualified and **published as 0.5.0-beta8 on the opt-in beta feed** on 2026-11-11: native live zvol-to-zvol migration, mixed file/zvol guests, conservative pooled destination capacity/reservation and separate disk/RAM progress. No on-the-fly conversion, shared storage, automatic cleanup, HTTPS transport or new HA authority is introduced. The native hook must not invoke libvirt, ZFS or a peer: fresh runtime ZFS GUID checks are tied to boot and kernel device sequence before starts are admitted. Destination zvols reserve full capacity and retain the configured dedup policy. Existing file-only authorities remain readable; live capability 4 requires matching Beta8, while wire protocol 7 and ordinary workflow gates remain unchanged. See [qualification results and limits](BETA8-QUALIFICATION.md).

Qualification used snapshotted DEV01/DEV02, live write/canary integrity in both directions, source-retirement/destination-owner proof, protected Windows/BitLocker cold boots, cancellation/link failure, capacity/readonly/missing/replaced-volume/foreign-reference denial, native starts and the full isolated regression suite. Nine interrupted-reservation cases passed. DEV02's kernel hang required an outer-VM restart; persistence passed afterward, but graceful host shutdown was not qualified. Stable remains 0.4.9.

### Beta7 development scope

Accepted, lab-qualified and **published as 0.5.0-beta7 on the opt-in beta feed** on 2026-11-10. Native software-TPM 2.0 live migration, multiple independent raw/qcow2 file disks, empty CD-ROM drives, independent structural readiness blockers, and source replica-count/encoded-zvol-path fixes are included. See [qualification results and limits](BETA7-QUALIFICATION.md), including Windows/BitLocker acceptance and the corrected long-transfer idle-channel defect. Stable remains 0.4.9.

Require independent destination TPM reservations, matching swtpm/libtpms evidence, exact disk/TPM start-fence bindings and authenticated paused-receiver handover. Do not introduce shared storage or copy a running emulator's files. Preserve older source/partial state and all existing authority/reconciliation rules. Qualification must include bidirectional moves and destination full stop/start with TPM identity/sealed-secret checks; Windows/BitLocker is an explicit acceptance target, not implied by an Ubuntu pass. Test multiple mixed-format disks, duplicate/foreign/changed disks, empty versus populated CD-ROMs, mixed-peer rejection, cancellation/interruption and current legacy regressions on snapshotted DEV01/DEV02.

Live zvol storage is included in Beta8 and reviewed inactive-copy cleanup in Beta9. Shared storage/Ceph, AMD nested live migration, passthrough, automatic failover and recovery arming after hand-back remain separate work. Changing a CPU/XML profile still requires a full stop/start before it is active.

The current beta retains Beta5's pinned new-VM CPU default/native editor integration, UEFI, powered-off ordinary hand-back, readiness/progress and browser-session handling. Software TPM support is qualified in Beta7/Beta8; the earlier [Beta5 qualification record](TESTING-LIVE-BETA5.md) covered UEFI without TPM.

Later work includes a reviewed ownership handover from formerly live-managed VMs to coordinated recovery. Replication-only configuration after hand-back does not yet allow recovery arming. Beta8's zvol/TPM/multiple-disk support does not imply AMD nested live migration, passthrough or automatic failover.

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
