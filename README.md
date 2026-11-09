# unMotion beta update feed

This branch serves **unMotion 0.5.0-beta5** (Plugins-tab version **2026.11.09.02-0.5.0-beta5**). Requires Unraid 7.3.2 or later. The [stable feed](https://raw.githubusercontent.com/rtho782/unmotion/plugin-stable/unmotion.plg) and Community Apps installer remain on 0.4.9.

Opt into beta updates with the [beta installer](https://raw.githubusercontent.com/rtho782/unmotion/plugin-beta/unmotion.plg) through **Plugins → Install Plugin**. It downloads a separate SHA-256-verified TXZ and keeps future updates on this beta track. Stable installations are not switched automatically.

Beta5 provides experimental live migration for running BIOS or supported UEFI/no-TPM guests with one independent raw/qcow2 file disk, an active compatible custom CPU and ordinary bridge NICs. Both peers need matching Beta5 and the qualified matching QEMU/libvirt stack (libvirt 12.2.0). UEFI requires matching reviewed OVMF firmware and UUID-scoped raw NVRAM. TPM, passthrough, ISOs, zvols/multiple disks and recovery/replication-managed VMs are excluded. Only one live job may run per host. Start with disposable guests and verified backups.

Set a validated **default CPU mode for new VMs** in Settings. Unraid's native CPU dropdown offers that pinned mode without retargeting existing VMs or saved templates. Integration supports reviewed editor sources qualified on Unraid 7.3.2 and 7.3.4; unknown sources are not overwritten. CPU configuration preserves topology, pinning, existing VMX/SVM exposure and explicit opt-outs. Intel nested live moves require nesting and full CPU validation on both hosts; AMD nested live moves remain blocked. Cached observations never authorize migration.

After a settled live move, fully stop its owner and choose **Return to ordinary workflows**. Paired checks allow Cold/Warm Move, clone, CPU edits and replication-only configuration while retaining non-owner start fences. Recovery arming after hand-back remains blocked. Ordinary transfers of these VMs require guarded handover capability on both peers. Interrupted transactions can offer **Finish guarded handover** without starting VMs or deleting storage. Source/partial files are retained; automatic reclamation and failover are not implemented. Nested-lab TCP handover gaps were approximately 1.85–2.13 seconds, not a guaranteed 500 ms stun.

Cached local/paired CPU comparison, shared-mode suggestions and reviewed next-boot configuration remain available. CPU changes take effect after a full stop/start, not a guest reboot; live preflight separately checks the active VM. Existing Cold/Warm Move handovers remain powered-off and do not require a shared CPU mode where VM/guest settings are otherwise compatible.

Online Warm Move preparation/Update handles growing file images with verified received lengths. Copies remain provisional: cutover powers off the source and forces the final image delta even when file size/timestamps match.

Wire protocol **7** is unchanged; live capability is **2** and guarded ordinary capability is **1**. Older protocol-7 peers retain existing unmanaged workflows. Finish active operations, pause replication, reconcile live jobs and stop recovery/live-managed guests before upgrading. Pending hand-back or unresolved authority blocks upgrades/removal. Preserve the installed descriptor filename `unmotion.plg`; never delete authority records to bypass a blocked upgrade/start.

The descriptor is byte-identical to the versioned release asset. Read the [user guide](https://github.com/rtho782/unmotion-community-apps/blob/main/README.md), [release notes](https://github.com/rtho782/unmotion/releases/tag/0.5.0-beta5), [live-migration requirements](https://github.com/rtho782/unmotion/blob/0.5.0-beta5/docs/LIVE-MIGRATION-BETA.md), [CPU compatibility guide](https://github.com/rtho782/unmotion/blob/0.5.0-beta5/docs/CPU-COMPATIBILITY.md), [Beta5 qualification](https://github.com/rtho782/unmotion/blob/0.5.0-beta5/docs/TESTING-LIVE-BETA5.md) and [upgrade guidance](https://github.com/rtho782/unmotion/blob/main/docs/SECURITY-042.md).

Author: Richard Skinner. GPL-3.0-only.
