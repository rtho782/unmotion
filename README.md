# unMotion beta update feed

This feed offers **unMotion 0.5.0 stable** (Plugins version **2026.11.11.02-0.5.0**), requiring Unraid 7.3.2 or later. Both feeds currently install the same qualified SHA-256-pinned package. This descriptor retains the **plugin-beta** update URL; beta users remain opted in for future prereleases.

Install via [unmotion.plg](https://raw.githubusercontent.com/rtho782/unmotion/plugin-beta/unmotion.plg), or use **Plugins → Check for Updates → Update** on an existing installation. Keep its installed name **unmotion.plg**. The small PLG downloads a separate versioned TXZ and retains the verified current package for offline boots.

0.5.0 includes CPU compatibility/defaults, scoped file/zvol live migration with optional experimental software TPM 2.0, reviewed inactive-copy cleanup, replication retirement/recreation and guarded ordinary hand-back. Live controls retain beta labels in the tested interface. Both live peers need matching 0.5.0, live ABI4/native-data1, an active compatible custom CPU and the qualified matching virtualization stack (libvirt12.2.0). Passthrough, attached ISOs, AMD nested live migration and other unsupported layouts remain blocked. No automatic failover is provided.

Live disk/RAM traffic uses a packaged native connector over pinned SSH with disposable job-restricted keys and receiver-owned session revocation. PHP supervises/control-validates, not VM-payload forwarding. Native ownership/fencing remains. The 30-minute transfer limit and documented capacity/headroom requirements apply; neither wire speed nor subsecond handover is guaranteed.

Before updating, pause replication, finish/reconcile jobs and cleanup, and fully stop recovery/live-managed guests. Preserve settings, pairings and authority records; never bypass upgrade/start guards. Update both peers before live migration: Beta9 lacks the native transport. Wire protocol7 remains unchanged for otherwise-supported ordinary operations.

The stable descriptor is byte-identical to the released PLG. The beta descriptor changes only the embedded feed URL; both reference the exact released TXZ/hash. See [release notes](https://github.com/rtho782/unmotion/releases/tag/0.5.0), [user guide](https://github.com/rtho782/unmotion-community-apps), [live requirements](https://github.com/rtho782/unmotion/blob/main/docs/LIVE-MIGRATION-BETA.md), [qualification](https://github.com/rtho782/unmotion/blob/main/docs/NATIVE-TRANSPORT-QUALIFICATION.md) and [upgrade guidance](https://github.com/rtho782/unmotion/blob/main/docs/PLUGIN-UPDATES.md).

Author: Richard Skinner. GPL-3.0-only. Keep verified backups.
