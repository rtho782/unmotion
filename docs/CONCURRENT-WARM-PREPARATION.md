# Concurrent Warm Move preparation

Author: Richard Skinner

The unpublished 0.4.1 candidate permits independent preparations from one source host to transfer concurrently. There is no longer a single exclusive lock covering every seed transfer. The compatibility lock is shared by new workers, but still excludes an older worker holding the former exclusive lock.

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
