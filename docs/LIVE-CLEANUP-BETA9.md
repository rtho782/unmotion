# Retained live-copy cleanup

Author: Richard Skinner

Available in [stable 0.5.2](https://github.com/rtho782/unmotion/releases/tag/0.5.2) through Community Apps and both feeds. The historical filename preserves documentation links; see [release scope and qualification](RELEASE-0.5.2.md).

New successful live jobs automatically reclaim exact obsolete source data after sessions close. The destination remains a normal VM without reboot/manual hand-back and its fresh normal-owner proof can endorse cleanup. Failed/cancelled/unresolved copies are not automatically discarded; blocked cleanup needs review/retry. Already-absent temporary TPM/NVRAM is recorded as absent, never permission to delete a replacement. Small fences/history remain; unknown legacy identities and recovery-managed authority still block deletion. See [normal-completion instructions/results](NORMAL-LIVE-COMPLETION-BETA6.md).

Use the reviewed workflow below for eligible retained older, failed or blocked copies. Retained data is not a continuously updated backup. Successful handover and cleanup progress are separate outcomes; a cleanup blocker does not stop the normal destination.

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

The current owning peer must prove either its exact settled live ownership or its normal-completion receipt with unchanged storage evidence. Recovery authority is a separate system and cannot be borrowed to authorize old-copy cleanup. Missing peers, corrupt records or changed identities block deletion and preserve data for investigation.

File/state deletion uses exact unlink/rmdir operations, and a volume uses non-recursive destruction of its admitted dataset. A durable per-object intent precedes deletion; target storage is flushed before its receipt is acknowledged. A retry can recognize the narrow interrupted-delete case without treating a reappeared/replaced object as disposable. Start fences and authority records are never cleanup targets.

## Native receive completion

Native completion requires a durable successful receipt bound to the exact domain, storage/XML contract, admitted boots and QEMU lifetime. Current native mode observes successful libvirt CLI completion or authenticated receiver completion before final ownership bookkeeping; native libvirt resumes the guest without an extra application-controlled pause. A paused/running VM or byte count alone is insufficient, and old-boot/replaced/incomplete receivers cannot borrow proof. The older guarded adapter validated successful non-cancelled Finish3Params; those historical receipts remain protected, not converted into new execution authority.

The response layout is checked against [libvirt 12.2.0's protocol definition](https://github.com/libvirt/libvirt/blob/v12.2.0/src/remote/remote_protocol.x); its [QEMU destination finish implementation](https://github.com/libvirt/libvirt/blob/v12.2.0/src/qemu/qemu_migration.c) waits for incoming state processing. The adapter remains limited to that reviewed ABI. There is no unrestricted libvirt/shell fallback or automatic failover.

See [Beta9 qualification](BETA9-QUALIFICATION.md), [live migration](LIVE-MIGRATION-BETA.md) and [replication retirement](REPLICATION.md#ending-replication-beta). Replication retirement and live-copy cleanup are different workflows.
