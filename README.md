# unMotion beta update feed

This branch serves **unMotion 0.5.0-beta7** (Plugins-tab version **2026.11.10.01-0.5.0-beta7**). Requires Unraid 7.3.2 or later. The [stable feed](https://raw.githubusercontent.com/rtho782/unmotion/plugin-stable/unmotion.plg) and Community Apps installer remain on 0.4.9.

Opt into beta updates with the [beta installer](https://raw.githubusercontent.com/rtho782/unmotion/plugin-beta/unmotion.plg) through **Plugins → Install Plugin**. Existing beta installations use **Plugins → Check for Updates → Update**. The small descriptor downloads a separate SHA-256-verified TXZ and retains the current package for offline boots. Stable installations are not switched automatically.

Beta7 supports experimental live migration of running BIOS or supported UEFI guests with one to sixteen independent raw/qcow2 file disks, optional software TPM 2.0 and ordinary bridge NICs. Both peers need matching Beta7, an active compatible custom CPU and the qualified matching virtualization stack (libvirt 12.2.0). TPM requires fresh matching swtpm/libtpms implementations; state moves natively into independent job/UUID-scoped storage, without shared storage. Empty read-only file CD-ROM drives may remain, but attached ISOs, live zvol/block disks, passthrough and recovery/replication-managed guests remain excluded. Only one live job may run per host.

The readiness dialog explains independent blockers and enables Start only after fresh full checks; submission checks again. Linux sealed secrets and Windows Server 2025 Core/active BitLocker passed lab live moves and protected cold boots. Read the qualification scope rather than assuming every Windows, TPM+PIN or Secure Boot configuration is covered. Keep guest recovery material and backups available.

Cached CPU comparison, shared-mode suggestions, reviewed XML application and a pinned default for new Unraid VMs remain available. CPU changes take effect after a full stop/start, not a guest reboot. Intel nested live moves require nesting and full CPU validation on both hosts; AMD nested live moves remain blocked. Cached observations never authorize migration.

After a settled live move, fully stop its owner and choose **Return to ordinary workflows**. Paired checks enable otherwise-supported Cold/Warm Move, clone, CPU edits and replication-only configuration, preserving non-owner fences. TPM guests still cannot be cloned. Recovery arming after hand-back remains unsupported. Retained source/partial disks, NVRAM and TPM state are not automatically reclaimed.

Scheduled replication supports explicit retirement with keep/delete choices, paired cleanup receipts and independent new cycles for the same VM/destination. Prepared-copy removal preserves source bases until destination acknowledgement. Displayed point counts follow authenticated inventory/pruning replies. Encoded zvol ordinary transfers require the optional encoded-path capability on both hosts; ambiguous names are rejected. No live zvol support is implied.

Wire protocol **7** remains unchanged; live capability is **3**, encoded-zvol capability **1**, and guarded ordinary/retirement capabilities retain their existing gates. Finish active operations, pause replication, reconcile live jobs and stop live/recovery-managed guests before updating. Unresolved ownership or pending hand-back blocks normal upgrades/removal. Keep the installed descriptor named `unmotion.plg`; never delete ownership records to bypass safety checks.

The descriptor is byte-identical to the versioned release asset. See the [user guide](https://github.com/rtho782/unmotion-community-apps/blob/main/README.md), [release notes](https://github.com/rtho782/unmotion/releases/tag/0.5.0-beta7), [live requirements](https://github.com/rtho782/unmotion/blob/0.5.0-beta7/docs/LIVE-MIGRATION-BETA.md), [qualification](https://github.com/rtho782/unmotion/blob/0.5.0-beta7/docs/BETA7-QUALIFICATION.md) and [CPU guide](https://github.com/rtho782/unmotion/blob/0.5.0-beta7/docs/CPU-COMPATIBILITY.md).

Author: Richard Skinner. GPL-3.0-only.
