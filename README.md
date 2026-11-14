# unMotion beta update feed

This opt-in feed offers **unMotion 0.5.2-beta4** (Plugins version **2026.11.13.03-0.5.2-beta4**), requiring Unraid 7.3.2 or later. Stable and Community Apps remain on **0.5.0**. This descriptor retains the **plugin-beta** update URL.

Install via [unmotion.plg](https://raw.githubusercontent.com/rtho782/unmotion/plugin-beta/unmotion.plg), or use **Plugins → Check for Updates → Update** on an existing installation. Keep its installed name **unmotion.plg**. The small PLG downloads a separate versioned TXZ and retains the verified current package for offline boots.

The beta adds native OpenSSH/libvirt live handover without the custom Go payload forwarder, RPC inspector or extra application-controlled pause. Both matching beta peers must explicitly enable **Native live migration trust** in Settings: temporary system-libvirt/forwarding access is administrator-equivalent, not a per-VM sandbox. Permanent pairing keys, strict host pins, persistent start fences and authenticated ownership reconciliation remain. A same-vendor older-generation destination is allowed only after fresh validation of the complete active guest CPU.

**SSH encryption** selects AES-128-GCM (default) or AES-256-GCM for outgoing live, Cold/Warm Move and replication connections. Live jobs pin their cipher; ordinary workers capture it at launch. No silent fallback or plaintext option is offered. Performance depends on the hosts and path. The 30-minute transfer limit and capacity/headroom requirements remain; neither wire speed nor subsecond handover is guaranteed.

Before updating, pause replication, finish/reconcile jobs and cleanup, and fully stop recovery/live-managed guests. Preserve settings, pairings and authority records; never bypass upgrade/start guards. Update both peers to matching **0.5.2-beta4** before live migration and enable native trust on each. Old queued guarded jobs are not converted; resolve them and create a fresh native job. Wire protocol 7/live ABI4/native-data1 remain, with additional native capability 1. Ordinary supported operations retain their own capability gates.

The feed is byte-identical to the released, lab-qualified beta PLG and references its exact tested TXZ/hash. Cached reboots of both labs, receiver loss during incomplete copying, source loss after native resume/durable success, authenticated reconciliation, fresh file/zvol round trips and a protected Windows TPM/BitLocker boot passed. Ambiguous outcomes can remain fenced for operator review; no automatic failover is provided. Passthrough, attached ISOs, AMD nested live migration and other unsupported layouts remain blocked.

See [release notes](https://github.com/rtho782/unmotion/releases/tag/0.5.2-beta4), [user guide](https://github.com/rtho782/unmotion-community-apps), [live requirements](https://github.com/rtho782/unmotion/blob/main/docs/LIVE-MIGRATION-BETA.md), [native trust/qualification](https://github.com/rtho782/unmotion/blob/main/docs/NATIVE-TRUSTED-MIGRATION.md), [host-loss/reboot results](https://github.com/rtho782/unmotion/blob/main/docs/NATIVE-HOST-LOSS-052-QUALIFICATION.md) and [upgrade guidance](https://github.com/rtho782/unmotion/blob/main/docs/PLUGIN-UPDATES.md).

Author: Richard Skinner. GPL-3.0-only. Keep verified backups.
