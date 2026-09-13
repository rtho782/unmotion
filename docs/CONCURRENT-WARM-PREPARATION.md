# Concurrent Warm Move preparation

Author: Richard Skinner

0.4.1-beta1 permits independent preparations from one source host to transfer concurrently. There is no longer a single exclusive lock covering every seed transfer. The compatibility lock is shared by new workers, but still excludes an older worker holding the former exclusive lock.

The change covers prepare, update and resume workers; it does not remove final migration/cutover serialization or allow simultaneous operations on one VM. Bandwidth limits apply **per worker**, not to the combined traffic. Concurrent preparations compete for disk/network throughput and capacity; free-space preflight is not a reservation of pool bytes against other writers.

## Guardrails

- A source-side admission transaction checks requests and records storage claims before launch. Simultaneous requests wait up to 60 seconds for this check, then ask for a retry if it remains busy, without failing an existing transfer. The lock is not held for transfer duration.
- The existing per-VM lock remains shared with migration, clone and replication workers. A per-seed worker lock rejects duplicate processes without writing FAILED into the active seed record.
- Queued/active prepare, update, resume and remove requests cannot overwrite the same seed's request/state. The worker records its PID; the launcher no longer writes a stale STARTING record over worker progress.
- Source-side seed records reserve dataset identities, mount paths and individual image paths. Shared datasets remain supported for different files; overlapping disks, directory descendants, known FUSE aliases and hard-linked files are detected. A retained or partial destination remains reserved until its prepared copy is removed.
- Destination claims compare the peer's durable host ID as well as its local pairing-record ID. Worker mappings are checked against admission claims before new snapshots/transfers, detecting intervening VM/storage-setting changes.
- Legacy active preparations without claims must finish first. Legacy prepared records use their recorded storage for collision checks. Existing protocol 5 receivers need no new RPC; neither base protocol nor negotiated recovery versions change.

These reservations coordinate seeds admitted by the **same source host**, not independent administrators or unrelated source hosts. They do not create a distributed storage lock, expand support for shared writable guest disks, or make out-of-band XML/storage edits safe. Existing destination conflict checks, readonly prepared storage, UUID checks, shutdown/final-delta cutover and resumable-receive preservation remain in force.

## Tests

`tests/seed-concurrency-regressions.php` covers independent admissions, same-VM conflicts, source/destination path overlaps, peer aliases, retained/partial reservations, legacy records, hardlinks, FUSE aliases, worker mapping changes, duplicate-worker guards and explicit POST gates. See `VERIFICATION.md` for the live lab results and their limits.

## Removing a preparation that never started

The Remove action now handles verified never-started FAILED records without requiring storage to exist. It archives the exact record directory under `/boot/config/plugins/unmotion/archived-seeds/`, retaining its original logs and metadata. It does not contact a peer or remove any VM, disk, snapshot or receive state in this record-only path. The UI reports that the record was archived rather than saying storage cleanup was started.

New preparations record a pre-storage flag and boot identity. Before taking a snapshot or beginning a copy, the worker must persist the storage-started flag. A pre-storage flag from another boot is not accepted as proof. Legacy lock-rejected records are recognised only by a restricted set of pre-transfer files, with no generation, pending snapshot, storage manifest contents or capability/inventory artifacts. Unexpected files, links, evidence of partial transfers, non-FAILED states and active worker/VM locks exclude record-only archival. Empty manifests alone do not authorise it; other cases retain normal cleanup safeguards.

Archives are local recovery/audit evidence, not anonymised reports. They are kept outside the active seed listing and are not automatically pruned. Retrieve their logs manually if needed; do not restore a seed into the active directory without checking current VM/storage ownership first.
