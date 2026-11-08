# unMotion beta update feed

This branch serves **unMotion 0.5.0-beta2** (Plugins-tab version **2026.11.08.02-0.5.0-beta2**). Requires Unraid 7.3.2 or later. The [stable feed](https://raw.githubusercontent.com/rtho782/unmotion/plugin-stable/unmotion.plg) and Community Apps installer remain on 0.4.9.

Opt into beta updates with the [beta installer](https://raw.githubusercontent.com/rtho782/unmotion/plugin-beta/unmotion.plg) through **Plugins → Install Plugin**. It downloads a separate SHA-256-verified TXZ and keeps future updates on this beta track. Stable installations are not switched automatically.

Beta2 adds cached local/paired CPU compatibility, suggested shared modes and reviewed bulk configuration of eligible local VM XMLs. Original XMLs are backed up, and CPU changes take effect at the next full stop/start—not a guest reboot. **Live migration itself is not implemented.** Cold Move and Warm Move still hand over with the VM powered off and do not require a shared CPU mode where the VM/guest are otherwise compatible.

Update both hosts for cached CPU comparison. Wire protocol 7 is unchanged; older protocol-7 peers retain existing workflows but cannot supply the optional CPU report. Finish active operations, pause replication and stop recovery-managed guests before upgrading. Preserve the installed descriptor filename `unmotion.plg`.

Read the [user guide](https://github.com/rtho782/unmotion-community-apps/blob/main/README.md), [release notes](https://github.com/rtho782/unmotion/releases/tag/0.5.0-beta2), [CPU compatibility guide](https://github.com/rtho782/unmotion/blob/0.5.0-beta2/docs/CPU-COMPATIBILITY.md) and [upgrade guidance](https://github.com/rtho782/unmotion/blob/main/docs/SECURITY-042.md).

Author: Richard Skinner. GPL-3.0-only.
