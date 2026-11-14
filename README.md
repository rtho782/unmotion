# unMotion

**Stable release: [0.5.2](https://github.com/rtho782/unmotion/releases/tag/0.5.2)**. Install through Community Apps or the [stable installer](https://raw.githubusercontent.com/rtho782/unmotion/plugin-stable/unmotion.plg). Both stable and [beta](https://raw.githubusercontent.com/rtho782/unmotion/plugin-beta/unmotion.plg) feeds currently offer 0.5.2, shown in Plugins as **2026.11.14.03-0.5.2**. Beta installations keep their opt-in channel for future prereleases. Update both peers before live migration.

Clone a VM locally, move it between paired Unraid hosts, or maintain scheduled ZFS replicas with coordinated manual recovery. unMotion provides preflight checks, progress and guarded ownership in the Unraid web interface.

Author: Richard Skinner. Copyright (C) 2026 Richard Skinner. Licensed under **GPL-3.0-only**, not GPLv3-or-later; see [LICENSE](LICENSE). Keep verified backups and test your workloads. A successful preflight is not a recovery plan.

After confirmed live handover, the destination is a [normal VM without reboot or manual hand-back](docs/NORMAL-LIVE-COMPLETION-BETA6.md), with its captured source autostart choice restored. Verified obsolete source disks/NVRAM/TPM are automatically removed after transfer sessions close. Failed/unresolved copies remain retained; cleanup blockers are reported separately from handover success. Small non-owner start fences and history remain. Cold/Warm Move, CPU editing, cloning and replication/recovery use their normal requirements: Cold Move needs shutdown, CPU changes need a full boot and TPM guests cannot be cloned.

## Features

- **Cold Move:** transfer a powered-off VM and start it on the destination.
- **Warm Move:** prepare/update storage while the source runs, then shut down for the final transfer and startup. Independent preparations/cutovers can run concurrently; shared-storage/same-VM conflicts remain blocked.
- **Live migration:** copy a running guest's disk and RAM without a shutdown/reboot, with a handover pause. Supports one to sixteen independent raw/qcow2 file disks or whole raw zvols, including mixed guests, BIOS or reviewed UEFI, and optional native software TPM 2.0.
- **CPU preparation:** cached compatibility masks, all-peers comparison, a suggested shared mode, reviewed persistent-XML application and a pinned default for newly created VMs. Changes to an existing VM's CPU require a full stop/start.
- **Storage and media:** native resumable ZFS streams for dedicated VM datasets/zvols, sparse image transfers for ordinary/shared layouts, verified NVRAM/TPM handling and Cold/Warm ISO copy/map/omit choices.
- **Local cloning:** independent disks, new VM identifiers/MACs and optional Ubuntu Guest Agent identity/DHCP customization. TPM guests cannot be cloned.
- **Scheduled replication:** dedicated-ZFS incremental points with five-minute to 24-hour RPO choices, retention, consistency evidence and opportunistic powered-off TPM checkpoints. End a cycle with keep/delete choices and create a separate new cycle for the same VM/destination.
- **Coordinated manual recovery:** source fencing, activation-owned storage copies, managed autostart and exact single-use native start permits. Recovery needs eligible Guest Agent evidence and a reachable, stopped source.
- **Reviewed cleanup and recovery actions:** reclaim proven inactive live copies without deleting owner data, fences or history; reconcile interrupted handovers; finish the selected source policy after repairing destination startup.
- **Responsive inventory and diagnostics:** progressive cached display observations with fresh operational checks; reviewable job/VM/storage/host reports that users download or submit themselves.

## Live migration requirements and limits

Both hosts need matching **0.5.2**, reciprocal pairing, native capability 1, live ABI capability 4, native-data capability 1 and a verified native start fence. Enable **Settings → unMotion → Native live migration trust** on both hosts. This temporarily grants administrator-equivalent system-libvirt/forwarding access to the trusted peer, not a per-VM sandbox. Permanent pairing keys remain restricted. The reviewed stack uses **libvirt 12.2.0**, matching QEMU and the same versioned Q35/i440fx machine; unsupported stacks fail preflight.

Use an active compatible custom CPU on same-vendor hosts. Fresh validation of the complete active CPU and nested features can allow migration to an older physical generation; a newer host alone is not compatibility proof either. Settings can suggest/apply a shared mode, but cached matches are not migration authority. CPU changes require a full stop/start. Older masks may reduce performance or hide instructions. Cold/Warm Move do not require a shared mode and remain useful for otherwise-compatible Intel/AMD hosts.

Live storage must use supported direct-pool raw/qcow2 files or whole raw zvols; zvols remain zvols with full reservations, while files stay sparse. No passthrough, attached ISO, native ZFS-encrypted zvol, backing chain, unrelated block device, pinning, huge pages or recovery/replication-managed guest. Equivalent networks are your responsibility. AMD nested live migration remains blocked; Intel nesting requires fresh validation on both hosts.

Only one live job may run per host. The worker requests abort at **30 minutes**; budget disk transfer as well as RAM convergence. The measured encrypted throughput needs headroom over guest dirtying; 4x is recommended. A fast NIC does not promise wire speed or a subsecond handover. Disk and RAM progress are separate observations, not an overall percentage or guaranteed ETA.

Disk/RAM traffic uses native OpenSSH/libvirt, with no Go payload forwarder, RPC inspector or extra application-controlled pause. Select AES-128-GCM (default) or AES-256-GCM for outgoing paired-host connections, including Cold/Warm Move and replication. Disposable live-job keys are source-address/expiry bound and revoked with exact session cleanup. Native libvirt performs handover/resume; authenticated ownership bookkeeping follows after resume. This is administrative peer trust, not a per-path security boundary. See [the trust model](docs/NATIVE-TRUSTED-MIGRATION.md).

Live controls retain **beta** labels in the interface; software-TPM support remains experimental within the documented scope. Windows Server 2025 Core with active TPM-only BitLocker passed live and protected cold-boot tests. Windows 11/TPM+PIN, AMD nested workloads and every hardware/guest failure combination are not qualified. See [live instructions](docs/LIVE-MIGRATION-BETA.md), [host-loss qualification](docs/NATIVE-HOST-LOSS-052-QUALIFICATION.md) and [stable promotion and measured performance](docs/RELEASE-0.5.2.md).

Use **Reconcile** after interrupted work; never manually start a retired source or delete authority records. **Clean up retained copy** reviews proven inactive data on the non-owner. New successful moves clean up automatically, but older unproven file/state identities are not adopted or deleted by filename. A blocked cleanup does not stop the normal destination. Recovery still follows its separate eligibility and ownership rules.

## Install, pair and update

Requires **Unraid 7.3.2 or later**, VM Manager, SSH, sufficient capacity/RAM and appropriate destination networking. Install matching versions on both peers. End users do not install Go.

1. Install unMotion through **Apps**, or paste the stable installer URL into **Plugins → Install Plugin**. Keep the installed descriptor named **unmotion.plg**.
2. Open **Settings → unMotion** on each host; review image storage, zvol parent and ISO paths. Pools may have different names.
3. Pair by discovery or address. Review the peer's Ed25519 fingerprint and choose **Accept and pair**. Manual verification is optional. Pair only trusted hosts: restricted root keys still authorize privileged VM/storage operations.
4. Test the pairing, review a disposable VM's preflight and verify its destination boot, data and networking.

Both feeds offer **0.5.2**, shown in Plugins as **2026.11.14.03-0.5.2**. A fixed installer date/sequence identifies each release; application and GitHub versions remain semantic. Beta installations retain their channel rather than silently switching to stable.

Use **Plugins → Check for Updates → Update**, then refresh unMotion. Before updating, pause replication and finish/reconcile jobs and cleanup. Normal completed owners need no shutdown solely for an update. Still-managed older owners need exact compatible fencing and their original admission; unknown changes remain blocked. Active recovery-managed guests still require shutdown. Read the specific Plugins-console reason rather than deleting ownership records; see [upgrade guidance](docs/PLUGIN-UPDATES.md#running-migrated-vms-during-an-update). Update both hosts before live migration; beta6 and stable 0.5.2 are not matching live versions. Pairings, settings and state are preserved. Confirm pairing/native-hook readiness afterward. unMotion never restarts VM Manager automatically.

Wire protocol 7 remains unchanged since 0.4.2. Otherwise-supported ordinary protocol-7 transfers retain their capability gates. New pairing needs 0.4.7 or later on both hosts. For 0.4.1 or earlier, finish/remove prepared partial work on compatible old peers before a coordinated upgrade; see [protocol upgrade guidance](docs/SECURITY-042.md).

The small PLG downloads a versioned, SHA-256-pinned TXZ from GitHub Releases. The verified current package remains cached for offline boots. Older recognized package downloads are cleaned after successful registration; settings, logs, newer packages and VM storage are preserved. Initial/uncached installs need GitHub access. See [plugin updates](docs/PLUGIN-UPDATES.md).

## Replication, recovery and source policies

Replication needs dedicated ZFS objects, not shared datasets, native ZFS encryption or qcow2 backing chains. Use ZFS Master or another storage tool to arrange storage; unMotion does not convert it. Replica points stay read-only/inert; manual recovery uses separate writable activation copies.

Arming recovery gives unMotion control of native autostart. Use **Start source VM** / **Start recovered VM** in unMotion; unauthorized native starts are rejected before QEMU. Automatic failover, unreachable-source recovery, quorum/witness voting, alternate TPM selection and automated failback are not implemented. Cold failback is preflight-only.

Cold/Warm Move can retain storage, retain/rename the source, or delete proven source data after five minutes of destination runtime. Holds, unrelated contents or uncertain ownership block deletion. If destination startup fails, repair/start it and use **Resolve migration** to finish the original source policy without retransferring or overwriting repaired XML. Do not run both copies.

Removal refuses armed/unresolved recovery, retained replicas or unfinished retirement/cleanup. Finish the paired workflows before uninstalling; do not delete ownership records to bypass guards.

## Documentation and support

- [Complete Unraid user guide](https://github.com/rtho782/unmotion-community-apps)
- [CPU compatibility/defaults](docs/CPU-COMPATIBILITY.md) · [Cloning](docs/CLONING.md)
- [Live migration](docs/LIVE-MIGRATION-BETA.md) · [Retained-copy cleanup](docs/LIVE-CLEANUP-BETA9.md)
- [Replication](docs/REPLICATION.md) · [Recovery/fencing](docs/RECOVERY.md)
- [Migration resolution](docs/MIGRATION-RESOLUTION.md) · [Firmware setting](docs/OVMF-FIRMWARE.md)
- [Architecture](docs/ARCHITECTURE.md) · [Roadmap](docs/ROADMAP.md) · [Verification](VERIFICATION.md)

Use **Report a problem** on a job to review/edit anonymised diagnostics, download/copy them or open a [GitHub issue draft](https://github.com/rtho782/unmotion/issues). Nothing is uploaded automatically. Sign in to GitHub and submit the reviewed report yourself, or share the saved file with someone helping you. Review paths/logs for private information first.

## Development

The package root is src/rootfs. Build/verify on Linux with PHP CLI, Bash, Node.js, Go 1.24+, tar/xz and checksum tools. A private Go executable can be selected with UNM_GO; users do not need a compiler. The packaged connector targets Linux/amd64, has no external modules and includes the Go runtime's BSD licence alongside unMotion's GPL licence.

```bash
./scripts/verify.sh 0.5.2
./scripts/build.sh 0.5.2
```

See [development/release guidance](docs/DEVELOPMENT.md) and [native source/build details](native/live-stream/README.md). Publish the exact qualified artifacts, not an untested rebuild. External code contributions are not currently accepted; bug reports remain welcome.
