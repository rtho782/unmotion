# Live migration — 0.5.0-beta9

Author: Richard Skinner

[0.5.0-beta9](https://github.com/rtho782/unmotion/releases/tag/0.5.0-beta9) is an experimental prerelease on the [beta feed](https://raw.githubusercontent.com/rtho782/unmotion/plugin-beta/unmotion.plg). Stable and Community Apps remain on 0.4.9. Use disposable VMs and verified backups for initial testing.

Live migration copies a running VM's disk and RAM without a guest shutdown/reboot, preserving its UUID, MAC addresses, CPU profile and resources. A handover pause is unavoidable. A 500 ms stun is a target, not a guarantee: initial nested-lab tests observed approximately 1.85–2.13 second packet gaps, including ownership confirmation.

Cold migration and Warm Move retain their existing powered-off handover. They do not require a shared CPU mask and remain options for otherwise compatible Intel/AMD hosts.

## Storage, capacity and progress

Supported writable disks are whole raw ZFS volumes or independent raw/qcow2 files, including mixed guests. Zvols remain zvols under the destination's configured zvol parent, outside `domains`; file images use its direct-pool image directory. No on-the-fly conversion, shared storage or snapshot-only copy of a running disk is used. Both hosts require matching Beta9/live capability 4; protocol 7 is unchanged.

Live readiness budgets full virtual sizes rather than assuming sparse transfers. ZFS supplies a no-write estimate of each destination volume's full reservation; file/zvol budgets on the same pool are combined, with allocation overhead, quota-aware available space and 20% free-space headroom. Zvol reservation is verified after non-sparse creation; file images remain sparse. External allocations can still consume free capacity. Source, failed and cancelled copies retain space until explicitly reviewed for cleanup on the non-owning host; nothing is automatically reclaimed. The configured destination dedup policy is retained. See [retained-copy cleanup](LIVE-CLEANUP-BETA9.md).

Fresh source/destination ZFS GUID and geometry checks identify the original volume. A local proof binds that evidence to the host boot and kernel block-device sequence. Synchronous native hooks only read local state; boot/import/device replacement requires asynchronous local revalidation before starts can be admitted. Missing, replaced or resized volumes fail closed. Native ZFS-encrypted volumes and unrelated block devices are excluded; guest BitLocker is a separate supported encryption layer subject to qualification.

Progress separates disk copy from RAM pre-copy/convergence, shows remaining/total bytes and RAM passes when supplied, and labels stale observations. Rewrites/retransmission may increase processed/total bytes or remaining work. Neither these statistics nor destination transport-byte counters are a combined completion percentage, unique-data measurement or guaranteed ETA. The handover still requires source retirement proof before destination resume.

If an attempt was rejected before the receiver recorded a job, **Reconcile** can confirm that the receiver is undefined, QEMU is absent and it holds no owning/in-flight/recovery authority. Fresh authenticated source callback proof is required before recording cancellation and returning source ownership. Any untracked partial data is retained. A stopped source is not automatically started; loss of a reservation record is never itself permission to start a copy.

See [Beta9 qualification results and limits](BETA9-QUALIFICATION.md), including actual copy/handover host-loss checks, and inherited [Beta8 storage qualification](BETA8-QUALIFICATION.md).

## UEFI and return to ordinary workflows

For UEFI live moves, both hosts must run matching Beta9 builds and matching reviewed read-only Unraid OVMF firmware. Firmware size/SHA-256 are checked freshly. Variables must be a raw, regular, UUID-scoped file under `/etc/libvirt/qemu/nvram/`. The receiver reserves a unique variables file; QEMU transfers pflash state and persists it on resume. Old source and aborted variables files are retained, not overwritten or deleted. General pool-resident/custom NVRAM layouts remain Cold/Warm Move options, not live support.

Software TPM 2.0 (`tpm-tis` or `tpm-crb`) can migrate through QEMU's native state stream with fresh matching swtpm/libtpms builds on both hosts. Each incoming attempt reserves its own job/UUID-scoped state directory; no shared storage or copy of a running TPM's files is required. Old source and failed-attempt state is retained. Passthrough/external TPMs, TPM 1.2 and encrypted/custom-profile TPM backends are excluded. Guest disk encryption is separate: active BitLocker was qualified on Windows Server 2025 Core, including protected cold boot after migration. Keep recovery keys available; TPM+PIN and every Secure Boot/PCR configuration are not separately qualified.

After a settled live move, fully power off its owner. In that host's live-job row, choose **Return to ordinary workflows**, review the paired checks and confirm. Both hosts must be reachable, with the peer's copy absent/stopped and retired or aborted. The owner is fenced while the peer acknowledges its retained non-owner fence; an interrupted acknowledgement leaves a retryable pending hand-back, not permission to start either copy.

Hand-back enables otherwise-supported Cold/Warm Move, cloning, CPU edits and replication-only configuration on the owner; ordinary restrictions still apply, including no cloning of TPM guests. Non-owner copies remain natively fenced even if their names or hardware change. Ordinary transfers of these VMs require Beta5 or later on both hosts. During cutover, the source is fenced while copying and durably retired before destination ownership can be committed. A lost post-transfer acknowledgement never restarts the source. **Finish guarded handover** can recheck and complete an interrupted definition/authority transaction without starting a VM; then start the destination when ready and use **Resolve migration** to finish the original source policy. Retained storage is not reclaimed automatically.

Recovery arming of formerly live-managed VMs is blocked: moving responsibility from the live ownership ledger to the recovery authority system is a separate design/test task. Replication configuration alone does not grant recovery or automatic failover.

The destination displays received transport bytes while its execution remains fenced; these include protocol overhead and are not an estimate of unique disk data. Active/next-boot CPU badges and local eligibility reasons are display evidence only. Expired browser login/CSRF responses stop further plugin requests and ask for a reload; background server jobs continue independently.

Wire protocol 7 is unchanged. Live capability 4 covers the typed file/zvol, TPM and multiple-disk contract; matching Beta9 is required for live moves. `liveOrdinaryVersion: 1` advertises guarded ordinary transfers. Older peers retain existing unmanaged protocol-7 workflows subject to their optional capability checks. Encoded zvol transfers in ordinary workflows require the separate `zvolPathVersion: 1` capability on both hosts.

## Requirements

- Matching Beta9 builds, reciprocal pairing and a proven native start fence on both hosts.
- x86_64 KVM, a confirmed same/newer CPU generation from the same vendor and a compatible **active** custom CPU definition. Apply a shared mode and fully stop/start first; changing next-boot XML or rebooting inside the guest is not enough.
- Exact versioned Q35/i440fx machine, matching QEMU/libvirt versions and unchanged resources. The migration-only control adapter is qualified for **libvirt 12.2.0**; other versions fail preflight without an unrestricted fallback.
- One to sixteen independent raw/qcow2 files on direct pool/disk paths or whole raw ZFS volumes, including mixed guests, with `cache="none"` or `directsync`. Every disk is reserved and bound to the job, and capacity is checked in aggregate. No user-share indirection, backing chain, internal image snapshot, encrypted image container, hard link or disk used by another VM. Zvols must be writable, device-visible and without native ZFS encryption; guest BitLocker is qualified separately.
- BIOS or supported UEFI firmware, optional supported software TPM 2.0, and ordinary bridge NICs. UEFI and TPM require the layouts and matching implementations described above. Bridge names are checked; equivalent physical networks and in-guest VLAN settings remain your responsibility.
- Intel VMX guests require KVM nesting enabled on both hosts, a fully validated nested CPU contract and compatible nested workloads. CPU application preserves existing VMX/SVM exposure, but cached base-mask matches alone do not certify nested requirements. AMD SVM-enabled guests are blocked from live migration; the kernel warns of unsafe behavior with active nested AMD workloads. See [kernel guidance](https://docs.kernel.org/virt/kvm/x86/running-nested-guests.html).

PCI/GPU/USB passthrough, attached ISO media, unrelated block devices, host CPU/NUMA pinning, huge pages, memory hotplug, shared-memory/filesystem devices and QEMU overrides are excluded. Empty read-only file CD-ROM drives may remain; detach mounted ISOs before live migration. Replication/recovery-managed guests and VMs with prepared Warm Moves are excluded. Other workflows keep their existing support for these configurations.

Only one live migration may run per host. Independent ordinary Warm Moves retain their existing concurrency. While live-managed, a VM supports further live migration and native owner starts; Cold/Warm Move, cloning, replication-policy and bulk CPU changes require the powered-off hand-back described above.

## Workflow

1. Opt into the beta feed through **Plugins → Install Plugin** on both **test** hosts, then refresh unMotion. Finish active operations and stop recovery/live-managed guests before updating. Verify pairing/native-fence readiness and keep restorable backups.
2. Select the intended destination and choose **Live migrate (beta)** on a running VM. The styled readiness dialog opens immediately, lists independent blockers, and enables submission only after fresh full checks. Recheck after correcting a blocker; Start repeats operational checks. Cached CPU/inventory displays never authorize migration.
3. Start the job. It reserves independent destination disks and required firmware/TPM state, measures the encrypted link and guest RAM dirty rate, then copies disk/RAM while the source runs. At least **4× headroom** is recommended; disk writes and other traffic also matter. A short sample is not a throughput guarantee. Very poor measured headroom aborts; unavailable dirty-rate evidence is logged as unknown.
4. Follow **Live migration — beta** and its log on the source. Disk copy and RAM pre-copy/convergence are shown separately; remaining work can change with guest writes, and these observations are not an overall completion percentage or ETA. The destination row shows its reservation state and received transport bytes until commit. **Request cancellation** asks libvirt to abort, not force-power-off the source. unMotion requests abort after a 30-minute transfer limit. No postcopy, unsafe flag, automatic CPU throttling or timeout-based offline fallback is used.
5. Validate networking and applications after completion, including protected boot where TPM is used. The source definition is unregistered, but source and partial disk/XML/NVRAM/TPM evidence is retained. To reclaim proven inactive data, use **Clean up retained copy** on the non-owner's settled job, review the exact objects and explicitly confirm. Both peers must remain reachable; unproven legacy objects, start fences and job history are retained. There is no automatic cleanup.

Source native autostart is disabled before transfer. A durable source fence prevents new starts while ownership is unresolved/retired. The destination receives the VM paused and freshly confirms that the source QEMU is absent and durably retired before resuming its exact admitted QEMU. Normal native owner starts remain possible on the committed destination.

## Interrupted jobs

Do not manually boot a restored source XML, resume an uncommitted incoming VM or delete authority files. Use **Reconcile** on the source after its worker and native libvirt jobs have stopped. Both hosts must be reachable.

Reconciliation either completes a proven stopped-source/paused-destination handover, or proves the incoming VM aborted before returning source ownership. An uncommitted receiver needs a durable successful native-finish receipt bound to its current boot, exact QEMU lifetime and storage/XML contract: a paused VM or received-byte counter is insufficient, and an old-boot receipt cannot authorize a replacement. A stopped source is not automatically booted. A paused original source can resume only after exact process verification and destination absence. Lost runtime credentials require an explicit local reconciliation permit and fresh authenticated callback; credential renewal never grants VM execution authority by itself.

Unknown state, missing/corrupt records, a changed QEMU lifetime, a busy job or an unreachable peer blocks action. There is no automatic failover/force takeover. Finish/reconcile jobs and power off live-managed guests before upgrading. Unresolved ownership blocks normal upgrades/removal; verified early-boot package restoration preserves fencing. Existing Unraid hooks and recovery one-use permits remain in place.

## Transport and qualification

Wire protocol 7 is unchanged. Live migration is optional and requires this implementation on both peers; older protocol-7 hosts retain their existing workflows.

Control/RAM/disk traffic uses the existing pinned, forced-command root SSH pairing. No general remote shell, unrestricted libvirt socket, SSH forwarding grant, public migration listener or HTTPS peer API is introduced. The control adapter validates the reviewed ABI, exact job XML, disk/socket identities, operation order and paused-destination flags. Unknown/replayed operations fail closed.

The test lab is nested on one physical Intel system. [Beta9 qualification](BETA9-QUALIFICATION.md) covers actual source/destination loss during copying and completed-transfer handover, paired reconciliation, interrupted cleanup/reboot/retry, ZFS identity renumbering, mixed Unraid 7.3.4/7.4.0 and Linux TPM cold-boot/live integrity. The inherited [Beta8 qualification](BETA8-QUALIFICATION.md) covers bidirectional Linux mixed file/zvol and Windows Server 2025 Core/active BitLocker zvol moves, protected cold boots, capacity/identity rejection and interrupted reservations. Its kernel-hang caveat remains recorded. [Beta7 qualification](BETA7-QUALIFICATION.md) covers both software TPM frontends and sealed-secret persistence; see also [Beta5 qualification](TESTING-LIVE-BETA5.md) and the [initial live-migration record](TESTING-LIVE-BETA3.md). Windows/BitLocker was not freshly rerun on Beta9. Windows 11/TPM+PIN, i440fx end-to-end migration, heterogeneous physical hardware and physical 10/25 GbE still require separate qualification; the four nested host-loss cases do not certify every failure combination. This is not general-availability live-migration support.
