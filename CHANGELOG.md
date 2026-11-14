# Changelog

## 0.5.2-beta4 — 2026-11-14

- Add AES-128-GCM as the default outgoing SSH cipher, with AES-256-GCM selectable in Settings across paired operations, Cold/Warm Move, replication and live migration. Validate the exact option without fallback; live jobs pin it and ordinary workers capture it at launch. Performance depends on the host and path, not simply key length.
- Add explicit native live-migration trust on both hosts, disposable source-IP/expiry-bound credentials and native OpenSSH Unix-socket/libvirt transport. New jobs use native finish/resume without the Go payload forwarder or libvirt RPC filter. System-libvirt access is administrator-equivalent while authorised; permanent pairing keys remain restricted.
- Reject an old queued guarded job before preflight/reservation rather than mixing its transport with a new native receiver. Old job records, retained data and reconciliation remain available; create a new native job after resolving the old one.
- Prepare durable start-blocking intent before native handover, then reconcile ownership after native resume. Sync successful native completion bound to both admitted boots and the mapped contract; require authenticated retired-source absence and exact destination bindings before granting ownership. Preserve a subsequent guest shutdown/pause rather than booting/resuming it again. Ambiguous outcomes, retained source data, legacy jobs and existing restart fences remain protected. Native schema-2 jobs require their mode/cipher fields; wire protocol 7 is unchanged and native capability 1 plus matching versions is required.
- The core implementation passed file/zvol and Windows TPM/active-BitLocker round trips, protected cold boot, partial-copy cancellation/worker loss, post-resume guest shutdown with lost-worker reconciliation, blocked duplicate native starts, unrelated SSH survival, and actual Cold/Warm Move/full-incremental replication cipher selection. The final build additionally passed cached host reboots, receiver loss during incomplete copying, and source loss after native resume/durable success with authenticated reconciliation. See `docs/NATIVE-TRUSTED-MIGRATION.md` and `docs/NATIVE-HOST-LOSS-052-QUALIFICATION.md` for exact tests, inherited evidence and conservative failure limits.

### Included live-control performance work

- Reduce live-control command latency with event-driven process I/O; leave the generic command runner used by ordinary workflows unchanged.
- Collect live progress in a separately supervised display-only worker so a statistics query cannot synchronously delay source retirement or destination resume.
- Flush newly written authority records once per freshly identified containing filesystem, preserving durable commit/ownership ordering, and record non-authoritative handover phase timings after resume.
- Earlier delay-only qualification passed both lab suites, Linux file/zvol moves, display-worker failure isolation, Windows TPM/active-BitLocker live/protected-cold-boot checks, cancellation, fresh CPU denial and final runtime/key/settings audits. Comparable file round-trip pauses fell from 2.10/2.17 s to 1.42/1.37 s before the native redesign; these are historical bounded samples, not production or subsecond guarantees. See `docs/LIVE-HANDOVER-PERFORMANCE.md`. The native trust option and cipher selector above supersede the earlier planning proposal; no additional bulk-guard profiles are offered.

### Included CPU-eligibility correction

- Let a same-vendor VM with a freshly validated active CPU mask migrate to an older physical host generation. Remove physical-family chronology as a veto in both preflight and receiver reservation; retain fresh complete CPU/nested-feature, machine, software, firmware, storage and native ownership/fencing checks. A newer host alone is not compatibility proof either.
- Preserve protocol 7/live ABI4/native-data1; new native operations add capability 1 and require matching 0.5.2-beta4 peers. Existing otherwise-supported ordinary protocol-7 operations are unchanged. `docs/CPU-MASK-051-QUALIFICATION.md` records earlier private-build qualification, not a separate public 0.5.1 release.

## 0.5.0 — 2026-11-12

- Release CPU compatibility/defaults, scoped live migration with file/zvol and software-TPM support, guarded ordinary hand-back, replication retirement/recreation and reviewed inactive-copy cleanup. Live moves require matching 0.5.0 builds and native-data capability on both peers; otherwise-supported ordinary protocol-7 operations retain their existing gates. The wire protocol is unchanged.
- Replace the live disk/RAM PHP byte relay with a packaged Linux/amd64 native connector over approved inherited descriptors. PHP still admits/supervises endpoints and validates libvirt control RPCs. Preserve bounded buffers, fresh authority checks on every I/O iteration, supervisor-loss withdrawal, cancellation, total lifetimes and binary-output isolation; the destination bandwidth receiver is native too. Earlier PHP chunk-size diagnosis remains in `docs/THROUGHPUT-QUALIFICATION.md`.
- Negotiate a disposable Ed25519 key for each live job through the existing paired control interface. Restrict it to that job's approved data sockets, without shell/forwarding/listener access. Revoke credentials and terminate exact job SSH/native sessions on completion/abort, preserve unrelated SSH sessions and remove source private keys. New optional `liveDataTransportVersion: 1` is required on both live peers; no wire-protocol bump or legacy payload fallback. See `docs/NATIVE-TRANSPORT-QUALIFICATION.md` for tests and limits.
- Publish the exact lab-qualified native candidate package, without rebuilding, with fixed installer ID `2026.11.11.02`. Stable/Community Apps and the beta feed offer 0.5.0; the beta feed retains its channel URL for future opt-in prereleases. UI live controls and software-TPM support retain experimental labels and the documented qualification limits.
- Carry forward bounded physical-host file/zvol/Windows qualification, real native 0.4.9/Beta9 upgrades and an offline cached boot from the earlier candidate. The final native transport passed both complete lab suites, key/session isolation and revocation, Linux interruption/retry, Windows TPM/BitLocker live/protected-cold-boot tests and full-pattern mixed-disk round trips on Unraid 7.3.4/7.4.0. Earlier production fixtures were completely removed; production was not accessed for the transport replacement or release. See `docs/BETA9-QUALIFICATION.md` and `docs/NATIVE-TRANSPORT-QUALIFICATION.md` for exact bytes, inherited evidence and limits.

## 0.5.0-beta9 — 2026-11-11

- Add explicit review/cleanup of proven inactive live-migration copies, with paired owner callbacks and short ownership-change leases, per-object identity/reference checks, write-before-delete intents and retryable receipts. Preserve current-owner data, native start fences and job history. Recorded legacy zvol GUIDs can prove individual volumes; unrecorded file/TPM/NVRAM identities remain protected.
- Record successful, non-cancelled native libvirt finish as a durable receipt bound to the domain, storage/XML contract, host boot and receiver QEMU lifetime. Require it before committing an uncommitted receiver; paused state alone is not completion proof.
- Bind newly admitted ZFS file-cleanup identities to the filesystem dataset GUID and inode, preserving exact identity across import-order device renumbering without adopting older unproven paths.
- Correct the installed plugin description for the live-migration beta and add the GitHub support link to PLG metadata.
- Keep wire protocol 7 and live ABI capability 4 with matching Beta9 builds; inactive-copy cleanup adds optional `liveCleanupVersion: 1`. Published as an opt-in prerelease using the exact qualified candidate-3 artifacts and fixed installer ID `2026.11.11.01`; stable/Community Apps remain 0.4.9. See `docs/BETA9-QUALIFICATION.md` for completed checks and limits.

## 0.5.0-beta8 — 2026-11-11

- Extend native live migration to whole raw ZFS volumes and mixed file/zvol guests. Zvols stay block-backed in the configured destination parent, with independent job-owned volumes and full reservations; there is no live format conversion or ZFS-send shortcut.
- Bind fresh ZFS GUID/geometry checks to kernel block-device lifetime proofs. Native start hooks remain local and command-free; boot/import/replacement requires fresh asynchronous local verification.
- Budget file and zvol capacity together when they share a destination pool, including allocation overhead, quota-limited availability and 20% free-space headroom. Preserve destination dedup policy and journal provisioning intent before creating storage. Retain source/partial copies.
- Show separate disk-copy and RAM pre-copy/convergence statistics. Retransmitted/remaining bytes are observations, not a combined completion percentage, guaranteed transfer size or ETA.
- Reconcile live attempts rejected before a receiver job was recorded, including targets retaining an old non-owner record. Require authenticated callback/VM absence proof, persist a non-startable cancellation record, retain any partial data and never boot a stopped source automatically.
- Keep wire protocol 7; live capability 4 requires matching Beta8 peers. DEV01/DEV02 qualification is complete with the scope and limits recorded in `docs/BETA8-QUALIFICATION.md`. Published as an opt-in prerelease using the qualified artifacts and fixed installer ID `2026.11.10.02`; stable and Community Apps remain 0.4.9.

## 0.5.0-beta7 — 2026-11-10

- Keep an admitted disk's live-transfer channel available while other disks or RAM are copying. Bound its total lifetime to the 30-minute worker limit plus two minutes of completion/rollback grace, rather than disconnecting a legitimately idle small disk after five minutes. Fresh authority checks and cancellation remain mandatory.
- Add a native software-TPM 2.0 live-migration path with matching emulator/library checks, independent destination state, exact native fencing and retained old/partial state. No shared storage or running-file copy is introduced; encrypted/custom-profile TPM backends remain excluded. Lab qualification covers both frontends, sealed-secret persistence, Windows Server 2025/active BitLocker in both live directions, protected cold boots and cancellation.
- Extend the live contract to up to sixteen independent raw/qcow2 files and empty read-only CD-ROM drives, with per-disk ownership, aggregate capacity, exact repeated migration parameters and every-disk native bindings. Zvol/block disks and attached ISOs remain excluded.
- Expose read-only live readiness for ineligible VMs and list independent structural blockers. Fresh operational preflight still gates submission and runs again on Start.
- Record source-side point-count observations from verified destination inventory/retention replies. Display counts never grant recovery or storage mutation authority.
- Resolve encoded Zvol device aliases against kernel identity and unique volume inventory. Preserve actual dataset names for ZFS operations and rewrite only the admitted disk sources for Warm/cold moves, image conversion and recovery; reject ambiguous plus/space names. Affected transfers require the optional encoded-path capability on both peers.
- Keep wire protocol 7; live capability 3 and matching Beta7 builds gate the extended live contract. Published as an opt-in prerelease; stable remains 0.4.9.

## 0.5.0-beta6 — 2026-11-10

- Add reviewed **End replication** with keep/delete choices, immediate schedule admission fencing, coordinated standby disarming and durable paired cleanup receipts. Keep retained replicas inert and inventoried; require a separate explicit choice to discard resumable receives. Never delete source VM disks or power off ordinary source guests for retirement.
- Block cleanup on uncertain authority, defined/referenced destination VMs, foreign holds, clones, children, unrelated snapshots or unknown state artifacts. Retain bases until the exact destination acknowledgement is saved. Support blocked-operation retries and later deletion of kept replicas; detect stopped retirement workers without silently resuming cleanup.
- Fix Warm Move cleanup ordering: exact destination ownership/preflight and acknowledged deletion precede source snapshot release. Preserve preparation status separately from cleanup failure and archive successful cleanup logs. Do not adopt legacy protocol-5 copies or fabricate reservations.
- Check retained preparations before crossing an incompatible protocol boundary; preserve same-protocol updates and genuine early-boot fencing restoration. Refuse uninstall with retained replicas or unfinished cleanup so ownership metadata is not silently orphaned.
- Allow replication to be configured again for the same VM/destination after completed keep/delete retirement. Give each new cycle an independently bound identity, ZFS objects, snapshot names and state directory; retain old inventories/receipts and permit later cleanup of old retained copies without touching a new cycle. Serialize same-pair creation and reject unfinished retirement, missing proof and duplicate active cycles.
- Keep wire protocol 7, live capability 2 and ordinary hand-back capability 1. New optional `retirementVersion: 1`, `seedCleanupVersion: 1` and `replicationIncarnationVersion: 1` require supporting peers for their respective actions. Disable resumes an existing chain; a new cycle starts a full copy. Published as an opt-in prerelease; stable remains 0.4.9.

## 0.5.0-beta5 — 2026-11-09

- Fix growing online raw/qcow2 Warm Move seeds: verify the received length against rsync's single-file transfer statistics, not the source's later size. Keep destination-length checks and transfer-error handling strict. Force the final powered-off mutable-image delta even when size/timestamps match; verify stable source/destination lengths before proceeding.
- Add a locally validated, pinned CPU default for new form-created VMs, with an Unraid CPU-dropdown entry, explicit clearing and unchanged existing definitions. Preserve custom CPU profiles, nested features, topology and pinning through native form edits; saved form templates retain private, fixed CPU profiles. Only reviewed native-editor source builds are adapted; originals are backed up and restored on removal.
- Support UEFI live migration without TPM using matching SHA-256-verified OVMF firmware, UUID-scoped raw NVRAM reservations and QEMU's pflash migration. Preserve old/partial variables files and use `--keep-nvram` when unregistering definitions.
- Add reviewed powered-off hand-back to Cold/Warm Move, clone, CPU edits and replication-only configuration. Retain non-owner UUID fences; ordinary cutovers durably retire their source before an authenticated callback can grant destination starts. Add explicit completion of interrupted guarded ordinary handovers. Recovery arming for these VMs remains blocked pending a separate authority-system handover.
- Show active/next-boot CPU model evidence and local live-scope reasons without treating cached observations as permission. Report received transport bytes on the destination. Stop plugin requests after browser authentication/token expiry while server-side jobs continue.
- Handle Unraid's relative metadata namespace without allowing failed XML canonicalization to collapse distinct definitions into an empty hash. Preserve existing hashes for supported canonical XML.
- Preserve wire protocol 7 and CPU cache capability 2. Live migration uses optional capability 2 and matching Beta5 builds; ordinary hand-back transfers require capability `liveOrdinaryVersion: 1`. Publish as an opt-in prerelease on the beta feed; stable and the Community Apps installer remain on 0.4.9.

## 0.5.0-beta4 — 2026-11-09

- Fix CPU-configuration verification when libvirt moves topology before features. Normalize only known direct CPU-child ordering; feature policies, topology values, duplicates and non-CPU XML remain verified. Reapplying a matching mode is idempotent.
- Preserve existing VMX/SVM exposure, including implicit host-passthrough/host-model nesting and explicit opt-outs. Freshly validate each augmented CPU and KVM nesting; skip unsupported requirements rather than silently removing them.
- Read, back up and verify secure persistent XML so CPU changes retain console credentials. CPU configuration never restarts a guest.
- Compare full active/pending CPU contracts without stripping nested features; check Intel nesting on both live-migration hosts. AMD SVM CPU configuration remains supported, but SVM-enabled live migration is blocked because active nested AMD workloads are unsafe to migrate.
- Preserve protocol 7, cached host CPU reports and ownership fencing. Both peers need matching Beta4 for live migration. Stable and Community Apps remain on 0.4.9.

## 0.5.0-beta3 — 2026-11-09

- Add experimental live migration for running BIOS guests with one independent raw/qcow2 file disk, an active custom CPU profile and ordinary bridge NICs. Fresh checks cover exact machine/software compatibility, CPU vendor/generation/features, conflicts, disk isolation and capacity.
- Carry control, RAM and disk traffic through job-bound encrypted channels on the existing restricted pairing, without a general libvirt connection or shell. Pin the control adapter to the reviewed libvirt 12.2.0 ABI and reject unknown/replayed operations and unsafe flags.
- Receive the destination paused; durably fence source starts and prove source QEMU absence before destination resume. Add native admission/reconnect identity checks and explicit paired reconciliation, including guarded renewal after runtime credential loss.
- Measure encrypted throughput/RAM dirty rate, request libvirt cancellation rather than forced power-off, retain source/partial disks and refuse upgrades/removal during unresolved ownership.
- Add styled immediate preflight and separate live-job controls/logs. Wire protocol 7 and ordinary unmanaged cold/warm/clone/recovery behavior are preserved.
- First-beta scope excludes UEFI, TPM, passthrough, ISOs, zvols/multiple disks and recovery/replication-managed guests. One live job per host; migrated guests remain live-managed and cannot yet return to Cold/Warm Move, clone, replication-policy or bulk CPU workflows. Lab handover gaps were approximately 1.85–2.13 seconds, not a guaranteed 500 ms stun. Published as an opt-in prerelease; stable remains 0.4.9.

## 0.5.0-beta2 — 2026-11-08

- Order validated CPU levels by recognised host-vendor family preference, newest first, with descending numeric revisions within each family. Order legacy CPUs historically (Pentium III, II, Pentium, then 486), with synthetic KVM/QEMU baselines in a separate group below named generations. Keep deprecated and unranked levels inspectable without recommending them over known native levels.
- Suggest a ranked, non-deprecated exact CPU definition shared by every selected paired host. Refresh the pairing snapshot on each read; wait for all selected peers, withhold suggestions for unavailable/unconfirmed peers, and preserve manually inspected selections.
- Show one preferred revision per CPU family by default, using the highest exact common revision where available. Add a local-only Show all revisions toggle and informational Unraid-update guidance for confirmed same-family revision/definition differences, without assuming every difference is software-related.
- Build an automatic Q35/i440fx CPU overview from existing cached reference checks, without a machine-type selector. Combine only exact definitions validated in both references; keep uncertain or mismatching levels unconfirmed. Paired overviews use the existing cached-only operation and can compare identical host CPU definitions across different reference versions, without approving any VM's exact machine version for migration.
- Inspect saved VM definitions for out-of-scope machine families and non-x86_64/non-KVM configurations. These restrictions apply to the CPU/planned live-migration feature, not existing cold/warm moves. Keep exact VM machine metadata and diagnostic comparisons separate for future operational validation.
- Explain CPU modes as preparation for future live migration without a power-off, with a brief handover stun; distinguish powered-off cold/Warm Move handover and older masks' workload/performance trade-offs.
- Add reviewed bulk application of the selected/suggested CPU mode to eligible local persistent VM XMLs, with fresh per-machine validation, original XML backups, stale-review protection and individual skipped/partial results. Preserve non-CPU settings and running guests; changes take effect at the next full stop/start, not a guest reboot.
- Discover versioned x86_64 KVM CPU compatibility levels, expand migratable CPU feature definitions, and validate them locally with libvirt.
- Persist observations per effective CPU/hypervisor and machine-type context. Reuse them across reads, page loads, plugin updates and reboots; invalidate changed hardware, microcode/features or hypervisor context. Initial collection runs in the background, with single-collector locking and bounded retries after unavailability.
- Expose cached reports through an optional, authenticated paired-host operation (CPU capability version 2, wire protocol 7). Remote reads never run CPU validation or queue a collector. Older/offline/unconfirmed peers do not imply compatibility.
- Add an all-peers comparison table and exact-definition inspector in Settings. CPU model names alone do not establish a common level. Wire protocol 7 is unchanged; the stable feed remains 0.4.9 and live migration is not implemented.

## 0.4.9 — 2026-10-15

- Permit the literal OVMF `X-PciMmio64Mb` firmware option in VM XML, using a bounded decimal string and no other QEMU arguments or environment overrides. Preserve the setting through transfers; arbitrary command-line extensions and file-backed firmware options remain blocked.
- Advertise the exception as an optional protocol-7 capability and reject unsupported destinations during migration/replication preflight, before transfer. Both hosts need the update for VMs using this option; ordinary VM compatibility is unchanged.

## 0.4.8 — 2026-10-14

- Show the peer's Ed25519 SSH fingerprint in a cancellable **Trust this host?** dialog, with **Accept and pair** and optional manual verification instructions. A separate SSH session and pasted fingerprint are no longer required by the new UI.
- Read the fingerprint without sending credentials or creating pairing keys. Pair only against the explicitly accepted fingerprint; rescan and stop before password authentication if the key changes. Keep existing pins, reciprocal authenticated host-key exchange, restricted client keys and protocol-7 compatibility.

## 0.4.7 — 2026-10-13

- Require an independently obtained Ed25519 SHA-256 SSH host fingerprint before sending the remote root password during new pairing. Pin only that verified host key; exchange the reciprocal host key through the authenticated initiating connection. New pairings require 0.4.7 or later on both hosts. Existing protocol-7 pairings and pins are retained.
- Prove dataset isolation before selecting native image transfers and recheck it before migration/preparation snapshots. Child datasets, unrelated entries, symbolic links, hard links, nested mounts and uncertain inventory select sparse transfer of exact VM files or block an already admitted native plan.
- Apply original-VM ownership checks to normal source cleanup as well as manual resolution. Replace recursive source ZFS destruction with exact object/snapshot removal. Foreign snapshots, holds and descendants block cleanup before source unregistration; recheck filesystem contents under temporary read-only protection before destruction. Record the original property and mount state, retry exact-object busy failures within a bound, and restore retained datasets on failure.
- Require POST for job-history deletion and other persistent web mutations that previously relied only on POST parameters; keep Unraid's CSRF validation in place.
- Keep wire protocol 7 and recovery schema/authority unchanged. A 0.4.7 source supplies the new storage checks when using an existing older protocol-7 pairing; an older source still uses its installed cleanup behavior. Upgrade both peers for the complete protections.

## 0.4.6 — 2026-10-13

- Open cold-migration, Warm Move cutover and local-clone dialogs immediately with visible, cancellable safety checks. Keep submission disabled until checks finish, offer retries after failures, and discard cancelled or superseded responses.
- Retain previously loaded VM storage/guest-agent details during background scans while applying fresh basic state. Update only changed table cells across VM, prepared-move, job, replication and incoming-replica lists, preserving unchanged controls, focus and tooltips.
- Preserve log reading position and unchanged text selections across refreshes; follow new output only while reading at the bottom. Prevent overlapping log reads and stale responses from a different log.
- Keep unchanged destination options and paired-host controls in place during health refreshes, and show progress while testing a pairing.
- No migration, storage, recovery, authentication or protocol changes. Display observations remain non-authoritative; action-time safety checks remain fresh.

## 0.4.5 — 2026-10-12

- Serve a private RAM-only display inventory, populated by a single background collector shared by browser tabs and paired-host requests. Show basic VM rows before storage and guest-agent details, retain rows during refresh, and distinguish loading, empty and failed observations.
- Reuse VM XML within each display scan, eliminating repeated cross-VM libvirt lookups. Refresh after definition/lifecycle changes, on request and with a short expiry; display observation time. Prevent overlapping browser polls and suspend background polling in hidden tabs.
- Add capability-advertised `inventory-view` to the existing restricted protocol-7 peer interface. Preserve fresh `inventory`, preflight and all mutation-time safety checks; HTTPS peer transport is not included.
- Correct the lifecycle listener's mutually exclusive libvirt event options so events can drive display invalidation as well as existing lifecycle reconciliation.
- Allow verified early-boot package restoration before VM Manager is running. Continue validating durable authority, and retain normal managed-VM upgrade refusal whenever early boot cannot be proven. Manual installation and Community Apps use the same persistent plugin mechanism.

## 0.4.4 — 2026-10-12

- Atomically publish a complete, readable Avahi service advertisement on every startup/restart, and use the daemon's reload command instead of accidentally signalling its chroot helper. Newly created or previously root-only advertisement files no longer prevent discovery after installation, reboot or upgrade. Private configuration, pairing keys and runtime-state permissions remain unchanged.
- Add behavioral regression coverage for fresh advertisement creation under a restrictive umask, repair of an existing root-only file, repeated refreshes and discovery-disabled operation.
- Keep package sources in Unix line-ending format and reject executable hooks with Windows-style interpreter lines during packaging, including exports prepared on Windows.
- No migration, cloning, replication, recovery or wire-protocol changes. Protocol 7 remains compatible with 0.4.2 and 0.4.3.

## 0.4.3 — 2026-10-04

- Require Unraid 7.3.2 or later, matching the supported lab baseline.
- Prune recognized older unMotion TXZ downloads after a successful, registered plugin install/update. Retain the verified current package for offline boots and leave newer/unrecognized packages, links, directories and all settings/VM state untouched. Cleanup uncertainty retains downloads and does not fail an otherwise successful installation.
- Keep application version 0.4.3 while using fixed installer version `2026.10.04.01-0.4.3`. Commit release IDs for repeatable rebuilds and check both installer and application ordering before advancing a feed, including double-digit beta/patch versions.
- No migration, cloning, replication, recovery or protocol changes. Protocol 7 remains compatible with 0.4.2; earlier protocol versions still require the documented coordinated upgrade.

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
