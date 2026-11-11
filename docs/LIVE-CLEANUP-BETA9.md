# Retained live-copy cleanup — 0.5.0-beta9

Author: Richard Skinner

**Beta only:** [0.5.0-beta9](https://github.com/rtho782/unmotion/releases/tag/0.5.0-beta9) is available through the opt-in beta feed. Install matching Beta9 on both paired hosts. Stable and Community Apps remain 0.4.9; live migration and this cleanup workflow remain experimental. See the qualification report for the tested scope.

Live migration retains the original source disks and the data from failed/cancelled receivers. These are not continuously updated backups. Cleanup is a separate, explicit operation; successful migration never automatically deletes them.

## Review and remove an inactive copy

1. Open unMotion on the host holding the **inactive, non-owning copy**, not the current VM owner. Finish or reconcile interrupted handovers first. The old VM definition must be absent; cleanup does not stop or unregister VMs for you.
2. In the applicable live-job row, choose **Clean up retained copy**. Both paired hosts must support `liveCleanupVersion: 1`, be reachable and retain matching job/host identities.
3. Review the exact disks and state paths. The owning peer must prove its current live-ledger ownership and an existing owning VM. The preview lists any protected artifacts for which identity is unproven. Recorded allocation is an estimate, not a promise of space reclaimed: snapshots, deduplication and deferred allocation affect it.
4. Choose **Delete reviewed data** to queue cleanup of only those objects. The worker repeats the complete plan checks and renews a short authenticated owner lease before deletion. The owner VM need not shut down. New ownership changes respect the lease; it is not a VM execution permit.
5. Follow the separate cleanup status in the job row. **Cleanup receipt** records completion without removing the migration log, job history or either host's native start fence. Empty shared image-parent directories are not recursively removed.

An uncertain response is not permission to submit an unrelated new deletion. Refresh status and review again. If cleanup becomes **BLOCKED**, address the displayed reason, then choose **Review/retry cleanup**. Partial outcomes and the original object identities remain journalled. Deletion is not resumed automatically after a host restart; an explicit reviewed retry is required.

## What stays protected

- Current owner data, defined local VMs, active processes and unresolved live/recovery authority.
- A disk or state path referenced by any active/saved VM, including aliases and backing-file sources, or used by a loop device or host swap.
- Replaced GUIDs/inodes, changed image geometry, symbolic/hard links, nested mounts and changed host-state content.
- Zvols with snapshots, holds, dependent clones, children or resumable receives, mounted zvols/partitions, and volumes with kernel device holders. Cleanup never silently discards a partial receive or removes foreign snapshots/holds.
- Unrecorded legacy file, NVRAM and TPM identities. A pathname alone is not deletion authority. Released zvol jobs that already recorded an exact GUID and geometry can prove those individual volumes; small unproven state files may remain protected alongside them.
- Unknown objects created during interrupted provisioning. The worker never adopts them merely because their names resemble an unMotion job.

New ZFS file identities bind the dataset name/GUID and inode, not its temporary kernel device number, which can change when a pool is imported after reboot. Other filesystem identities still require an exact device/inode match. A changed filesystem identity blocks deletion; an old unproven identity is never upgraded from a filename merely to make cleanup succeed.

The first implementation requires the current owning peer to remain live-managed. Cleanup after ordinary hand-back or transfer into coordinated recovery is not an authority-system handover and is not enabled by this workflow. Missing peers, missing/corrupt records or filesystem identity changes can block cleanup; data remains available for investigation.

File/state deletion uses exact unlink/rmdir operations, and a volume uses non-recursive destruction of its admitted dataset. A durable per-object intent precedes deletion; target storage is flushed before its receipt is acknowledged. A retry can recognize the narrow interrupted-delete case without treating a reappeared/replaced object as disposable. Start fences and authority records are never cleanup targets.

## Native receive completion

Beta9 also requires a durable receiver completion receipt before granting ownership to an uncommitted paused VM. It records a validated, successful, non-cancelled libvirt Finish3Params response and binds it to the exact domain, job storage/XML contract, host boot and admitted QEMU lifetime. A paused VM or received-byte counter alone is not proof that disk/RAM transfer completed. Rebooted, replaced or incomplete receivers cannot consume this proof. Existing committed owners keep their ordinary native-start semantics.

The response layout is checked against [libvirt 12.2.0's protocol definition](https://github.com/libvirt/libvirt/blob/v12.2.0/src/remote/remote_protocol.x); its [QEMU destination finish implementation](https://github.com/libvirt/libvirt/blob/v12.2.0/src/qemu/qemu_migration.c) waits for incoming state processing. The adapter remains limited to that reviewed ABI. There is no unrestricted libvirt/shell fallback or automatic failover.

See [Beta9 qualification](BETA9-QUALIFICATION.md), [live migration](LIVE-MIGRATION-BETA.md) and [replication retirement](REPLICATION.md#ending-replication-beta). Replication retirement and live-copy cleanup are different workflows.
