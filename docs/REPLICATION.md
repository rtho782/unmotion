# Scheduled replication transport

## Scope

Scheduled replication maintains verified ZFS recovery points for a VM on a paired Unraid host. The source remains authoritative. The destination stores inert replica objects and metadata, but does not define, mount, expose or start the VM.

Beta1 is the replication transport and inventory layer. Beta2 adds a separately capability-gated manual recovery control plane; retained replica objects remain inert and read-only, and activation uses exact activation-owned clones rather than modifying them. See [RECOVERY.md](RECOVERY.md).

## Storage boundary

A replication policy is accepted only when every writable VM disk is one of:

- a dedicated ZFS zvol; or
- a raw or qcow2 image in a ZFS filesystem dataset proven to contain only that VM's referenced disk files and no child datasets.

Shared datasets, ambiguous `/mnt/user` paths, non-ZFS images, native ZFS encryption and qcow2 backing chains are hard blockers. Migration can continue to use its sparse file-copy fallback; scheduled replication cannot. unMotion deliberately contains no dataset conversion tool and directs users to the ZFS Master plugin or another purpose-built storage tool.

Destination objects use stable policy/object identifiers rather than source basenames. Filesystem replicas remain `readonly=on`, `mountpoint=none` and `canmount=off`; zvol replicas remain `readonly=on`, `volmode=none` and `snapdev=hidden`. A receive always uses `-u`. The destination does not call `virsh define` during replication. Reservation and recovery-point publication also fail if libvirt already defines any running or stopped VM with the replica UUID; retained definitions from an earlier migration must be moved or explicitly unregistered first.

## Compatibility

Existing pairing, cold migration, Warm Move and cloning retain peer protocol version 5. Beta1 advertises a separate scheduled-replication capability and replication protocol version 1. A peer without that explicit capability is rejected before any source snapshot is created. A global wire-protocol bump is intentionally deferred until recovery/failover needs a witness-capable protocol.

## Policy and schedule

The allowed RPO values are 5, 15 and 30 minutes, then 1, 2, 4, 6, 8, 12 and 24 hours. The maximum retention count is:

```text
min(24, floor(24 hours / RPO))
```

The scheduler uses integer UTC epochs. It advances to the newest due slot after downtime and performs one run instead of creating a catch-up burst. A pending generation is always resumed before a new generation can be created. Lock contention is a delay, not a failed recovery point.

Policy configuration is durable under `/boot/config/plugins/unmotion/replications/`. Frequent progress belongs under `/var/lib/unmotion/replications/` so a five-minute RPO does not continually write the USB boot device. Source snapshot properties and GUIDs provide another durable record of the acknowledged base and an interrupted pending generation.

## Crash-safe generation transaction

Each generation follows this order:

1. Revalidate host identities, peer capability and the recorded storage layout.
2. Record the pending generation and exact source/destination object map.
3. Quiesce through QEMU Guest Agent when supported.
4. Create exact, non-recursive source snapshots and apply policy-specific holds.
5. Thaw the guest on every normal, error and signal path.
6. Transfer each object using full or incremental `zfs send` and resumable `zfs receive -s -u`.
7. Verify every destination snapshot GUID against its source snapshot GUID.
8. Atomically publish the complete destination recovery-point manifest.
9. Atomically commit the new source base and clear the pending generation.
10. Release and destroy only the exact previous source-base snapshots.
11. Prune only exact, owned destination snapshots not selected by retention.

No partially transferred multi-disk point is published. Receive tokens, pending snapshots and holds survive interruption. unMotion never abandons a receive token automatically, never uses recursive snapshot/send operations for an individual VM, and never prunes by wildcard or prefix. A cleanup failure leaves a verified point available and reports the retained object instead of claiming deletion.

## Retention

Historical points live on the destination; the source normally retains only the latest acknowledged incremental base and any pending generation.

Retention divides each UTC day into `N` equal buckets, where `N` is the selected retention count. It keeps the newest verified point in each bucket intersecting the latest 24 hours and always keeps the newest verified point, even if replication has been failing for more than a day. Empty buckets are not backfilled. Transfer completion time does not affect selection; the disk capture epoch does.

When recovery is armed, the destination evaluates recovery eligibility before the source constructs a prune request. If the normal retention set contains no eligible point, unMotion safety-pins the newest eligible point as one additional point. If exact eligibility validation is temporarily unavailable, it conservatively pins the newest published non-replication-only candidate instead. This exception is limited to one extra point and disappears automatically once the configured retention set again contains an eligible point; unarmed policies continue to enforce the selected count exactly.

Failed, partial, GUID-unverified, deleting or cleanup-failed points are not recovery points. Materially future-dated timestamps suppress destructive retention cleanup until the clock is trustworthy again. Foreign ZFS holds are never released.

## Guest consistency and recovery eligibility

A reachable Guest Agent is not assumed to support filesystem freeze. unMotion checks `guest-info` for enabled ping, interface, freeze-status, freeze and thaw commands. A freeze must report that it froze at least one filesystem; thaw is bounded and guarded by exit/signal traps.

For every point, unMotion records Guest-Agent-observed interfaces, MAC addresses, usable IP addresses, observation time and the selected future liveness-probe address. If the agent or required commands are unavailable, storage replication may continue as crash-consistent replication-only data, but the point is marked ineligible for future recovery activation.

## TPM and UEFI NVRAM

TPM and NVRAM state is checkpointed separately from disk snapshots, hashed deterministically, transferred as content-addressed artifacts and never extracted into live destination libvirt paths during replication. Beta4 additionally accepts an ownership-proven custom NVRAM file beside the VM image, archiving it at a canonical UUID-scoped libvirt path for recovery. TPM discovery is shared with migration and rejects multiple distinct UUID stores.

Checkpoint quality is recorded as:

- `safe`: the VM remained powered off and hashes were stable;
- `best-effort`: a running VM was briefly suspended and staged hashes were stable;
- `unstable`: state or hashes changed during capture; or
- `missing`: expected state could not be located.

A running pause is not treated as a safe TPM copy. The latest verified safe TPM checkpoint is retained until a newer safe checkpoint is verified, even when retention removes the disk point that first referenced it. Beta1 opportunistically captures or promotes a safe checkpoint only when a scheduled or manual replication run observes the VM powered off. Beta2 adds a persistent libvirt lifecycle monitor that wakes the replication worker on shutdown; publication and source-base commit must still complete before the event request is acknowledged.

## Beta2 recovery boundary

Beta2 recovery requires a Guest-Agent-eligible point, places source-side autostart under unMotion control, and requires an authenticated coordinated grant from the reachable, stopped and fenced source before manual activation. Retained recovery objects stay read-only and inert; activation uses exact, separately owned clones. Host shutdown/reboot prepares a durable destination hold before the local service stops, and startup remains fenced until the peer reconciles authority.

These controls are separate from the beta1 replication transport and use recovery protocol 1 negotiated over protocol 6. Automatic failover, network-silence claims and witnesses are not enabled in beta2.
