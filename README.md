# unMotion beta update feed

This branch serves **unMotion 0.5.0-beta4** (Plugins-tab version **2026.11.09.01-0.5.0-beta4**). Requires Unraid 7.3.2 or later. The [stable feed](https://raw.githubusercontent.com/rtho782/unmotion/plugin-stable/unmotion.plg) and Community Apps installer remain on 0.4.9.

Opt into beta updates with the [beta installer](https://raw.githubusercontent.com/rtho782/unmotion/plugin-beta/unmotion.plg) through **Plugins → Install Plugin**. It downloads a separate SHA-256-verified TXZ and keeps future updates on this beta track. Stable installations are not switched automatically.

Beta4 provides experimental live migration for running BIOS guests with one independent raw/qcow2 file disk, an active compatible custom CPU and ordinary bridge NICs. Both peers need matching Beta4 and the qualified matching QEMU/libvirt stack (libvirt 12.2.0). UEFI, TPM, passthrough, ISOs, zvols/multiple disks and recovery/replication-managed VMs are excluded. Only one live job may run per host. Start with disposable guests and verified backups.

CPU configuration preserves existing Intel VMX/AMD SVM exposure and explicit opt-outs, validates each augmented guest CPU locally and retains console credentials. Read-back verification tolerates harmless CPU-child reordering while still detecting substantive changes. Intel nested live migration requires KVM nesting and the complete compatible CPU contract on both hosts. SVM-enabled live migration is blocked in this beta; otherwise-supported Cold/Warm Move remains available. A cached base-mask match alone does not qualify nested requirements.

Migrated guests remain live-managed: native owner starts and further live moves work, but their Cold/Warm Move, clone, replication-policy and bulk CPU changes are blocked. Safe hand-back/unmanage is not implemented. Source/partial disks are retained. Nested-lab TCP handover gaps were approximately 1.85–2.13 seconds, not a guaranteed 500 ms stun.

Cached local/paired CPU comparison, shared-mode suggestions and reviewed next-boot configuration remain available. CPU changes take effect after a full stop/start, not a guest reboot; live preflight separately checks the active VM. Existing Cold/Warm Move handovers remain powered-off and do not require a shared CPU mode where VM/guest settings are otherwise compatible.

Wire protocol 7 is unchanged; older protocol-7 peers retain existing workflows but lack live support. Finish active operations, pause replication, reconcile live handovers and stop recovery/live-managed guests before upgrading. Preserve the installed descriptor filename `unmotion.plg`; never delete authority records to bypass a blocked upgrade/start.

Read the [user guide](https://github.com/rtho782/unmotion-community-apps/blob/main/README.md), [release notes](https://github.com/rtho782/unmotion/releases/tag/0.5.0-beta4), [live-migration requirements](https://github.com/rtho782/unmotion/blob/0.5.0-beta4/docs/LIVE-MIGRATION-BETA.md), [CPU compatibility guide](https://github.com/rtho782/unmotion/blob/0.5.0-beta4/docs/CPU-COMPATIBILITY.md), [Beta4 qualification](https://github.com/rtho782/unmotion/blob/0.5.0-beta4/docs/TESTING-CPU-BETA4.md) and [upgrade guidance](https://github.com/rtho782/unmotion/blob/main/docs/SECURITY-042.md).

Author: Richard Skinner. GPL-3.0-only.
