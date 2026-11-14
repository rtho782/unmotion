# Live migration

Author: Richard Skinner. Available in **[stable 0.5.2](https://github.com/rtho782/unmotion/releases/tag/0.5.2)** through Community Apps and both plugin feeds. The historical filename preserves existing links. Live controls retain beta labels in the interface; software TPM support remains experimental within its qualified scope.

Live migration copies eligible guests' disk and RAM without a shutdown/reboot, with a handover pause. **Cold/Warm Move remain separate powered-off cutover workflows.** Use disposable guests first and keep verified backups.

## Trust and encryption

Install matching **0.5.2** on both reciprocally paired hosts, then enable **Settings → unMotion → Native live migration trust** on both. Temporary system-libvirt/SSH forwarding access is administrator-equivalent, not a per-VM/path sandbox. Use it only with fully trusted peer administrators. Permanent pairing keys remain restricted; disposable live keys are source-address/expiry bound, revoked after use and their exact sessions terminated. See [trust and cipher details](NATIVE-TRUSTED-MIGRATION.md).

Native OpenSSH/libvirt carries control, RAM and disk traffic without a custom Go payload forwarder or RPC inspector. Libvirt performs finish/resume; unMotion's durable ownership bookkeeping follows after resume rather than imposing an extra normal-path guest pause. Persistent non-owner start fences and authenticated reconciliation remain. This trust model is not protection against a malicious privileged peer.

Choose AES-128-GCM (default) or AES-256-GCM for outgoing paired-host SSH, including Cold/Warm Move and replication. Live jobs pin the chosen cipher. Existing connections retain their selection; unsupported negotiation fails without plaintext or silent fallback.

## Requirements

- Exact matching application versions, live ABI4, native-data1, native1 and verified native start fencing. Normal completion/ordinary return requires `liveNormalVersion: 1`.
- Reviewed **libvirt 12.2.0**, matching QEMU and the same exact versioned x86_64 Q35/i440fx machine. Unknown stacks fail preflight.
- A complete compatible **active custom CPU**, same vendor and fresh destination/nested-feature validation. Newer-to-older physical generations can work with a validated common mask; a newer host is not automatic compatibility proof. Applying next-boot XML requires a full stop/start before use.
- Ordinary bridge NICs with equivalent physical networks. Host VLAN/filter/script overrides are excluded; in-guest networking remains your responsibility.
- No passthrough USB/PCI/GPU, filesystem shares, shared memory, huge pages, hotplug memory/NUMA, host pinning/cache overrides, accelerated video/host audio, attached ISO, external backing chain or recovery/replication-managed guest. Empty read-only file CD-ROM drives may remain.
- Intel nesting requires host KVM nesting and fresh validation on both hosts. AMD SVM configuration is supported, but **AMD nested live migration is blocked**; use otherwise-supported Cold/Warm Move. unMotion does not change host KVM settings.

## Storage, capacity and progress

Use one to sixteen independent raw/qcow2 files or whole raw ZFS volumes, including mixed guests, with disk cache `none` or `directsync`. File disks need supported direct-pool paths and remain sparse. Zvols remain independent zvols under the destination's configured parent, outside `domains`, with full reservations. No live format conversion, shared storage or snapshot-only transfer of a running disk is used. Native ZFS-encrypted volumes and unrelated block devices are excluded; guest BitLocker is a separate layer.

Preflight budgets full virtual sizes, combines file/zvol requirements sharing a pool and includes allocation overhead, quota-aware availability and 20% headroom. Reservations are verified at creation. Other allocations and failed/blocked copies still consume capacity. Sparse usage is not a guarantee of wire bytes or future capacity. Destination dedup policy is preserved.

Fresh GUID/geometry and boot/kernel-device-lifetime proofs bind each zvol. Native hooks read local state only; reboot/import/replacement needs asynchronous local verification. Missing/replaced/resized objects fail closed. Images shared by another VM and ambiguous aliases are blocked.

Disk copy and RAM precopy/convergence show separate observations, remaining bytes and RAM passes. Disk mirroring may continue while RAM moves. Retransmission can increase counts; these are not unique-byte/wire measurements, a combined completion percentage or a promised ETA.

Only one live job may run per host. The worker requests abort after **30 minutes**. Budget disk transfer plus RAM convergence, with approximately **4× throughput headroom** over memory dirtying recommended. Workload and storage/network/cipher performance determine results; NIC speed alone does not guarantee convergence or a subsecond pause. See [bounded physical-host measurements](RELEASE-0.5.2.md).

## UEFI and normal completion

<a id="uefi-and-return-to-ordinary-workflows"></a>

BIOS and reviewed matching UEFI firmware are supported. UEFI variables must use supported raw UUID-scoped paths. Software TPM 2.0 (`tpm-tis`/`tpm-crb`) transfers natively into independent job/UUID-scoped state with freshly matching swtpm/libtpms builds, not a shared TPM disk or running-file copy. External/passthrough TPMs, TPM1.2 and encrypted/custom-profile backends are excluded. Preserve guest recovery keys and test protected boot.

The successful destination becomes a **normal VM without reboot/manual hand-back**. Captured source autostart is restored; native VM controls, Cold/Warm Move, CPU editing, cloning and replication/recovery use their usual eligibility rules. Cold Move still needs shutdown, CPU changes need a full boot and TPM cloning remains unsupported. Normal owners have no enduring live hardware/start admission or former-peer native-start dependency. Deliberately armed recovery uses its own authority system.

Verified obsolete successful-source disks/NVRAM/TPM are automatically removed after sessions close. Non-owner fences and history remain. **Cleanup BLOCKED** reports a separate result without stopping the normal owner or repeating migration. Failed/cancelled/unresolved copies remain retained. Older owners can normalize after both peers prove completion; unknown legacy file/state identities are never adopted by filename. See [normal completion](NORMAL-LIVE-COMPLETION-BETA6.md) and [cleanup](LIVE-CLEANUP-BETA9.md).

## Workflow

1. Finish/reconcile work, pause replication and follow [update precautions](PLUGIN-UPDATES.md) to install matching 0.5.2 on both hosts. Verify pairing/native-fence readiness and enable native trust on both.
2. Apply a suitable shared CPU profile if required, then fully stop/start the VM so it is active. Review device, firmware, storage and bridge eligibility.
3. Choose a destination and **Live migrate (beta)**. Review independent blockers and capacity. **Recheck** after corrections; submission remains disabled until fresh preflight succeeds, and Start rechecks both hosts.
4. Follow the source job/log. Server-side jobs continue if browser login expires; reload/sign in again. The source runs during copying and native libvirt performs the handover pause/resume.
5. Verify destination networking/applications and normal completion. Follow the separate automatic-cleanup status; review/retry a blocker rather than deleting records or starting an old copy.

## Interrupted jobs

**Request cancellation** asks libvirt to abort, not force-power-off the guest. After an interruption, use **Reconcile** on the source once its worker/native jobs have stopped and both peers are reachable. It may preserve a proven original source or complete a proven destination without another guest restart. A missing peer/job, paused receiver or byte count alone is not completion/ownership proof. Rebooted/replaced/mismatched evidence fails closed.

Durable start-blocking intents precede native handover. A synced successful-native-finish receipt binds both admitted boots, the exact QEMU/process and storage/XML contract; fresh retired-source absence and current receiver evidence are required for ownership bookkeeping. An ambiguous outcome remains fenced for review. Reconciliation does not automatically boot a stopped source or infer rollback from source absence. Do not manually resume an incoming VM, start a retained source, remove fences or fabricate authority.

Finish/reconcile active work and cleanup before updating or uninstalling. A normal owner needs no shutdown solely for a plugin update; a still-managed owner needs exact compatible fencing/original admission, and active recovery-managed guests require shutdown. Unknown transitions are refused. Both peers must update before further live moves; protocol7 and unrelated ordinary capability gates remain unchanged.

## Transport and qualification

The stable release promotes beta6 logic with version metadata changes only. Qualification includes both full lab suites, file/zvol/Windows TPM roundtrips and protected boot, cancellation, cleanup blockers and ordinary/recovery workflows. Native host-loss/reboot evidence is inherited from the unchanged beta4 transport; beta6 did not rerun every failure case. A later physical-host Windows roundtrip measured roughly 0.21–0.25s event pauses, not a guarantee for other workloads.

See [stable promotion scope](RELEASE-0.5.2.md), [beta6 qualification](NORMAL-LIVE-COMPLETION-BETA6.md), [native trust qualification](NATIVE-TRUSTED-MIGRATION.md) and [host-loss/reboot qualification](NATIVE-HOST-LOSS-052-QUALIFICATION.md). Windows Server 2025 Core with active TPM-only BitLocker is qualified; Windows11/TPM+PIN, every PCR/Secure Boot profile, AMD nested workloads and every physical failure window are not. There is no automatic failover or unreachable-source takeover.
