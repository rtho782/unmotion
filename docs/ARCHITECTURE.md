# Architecture

This describes stable 0.4.2: protocol-7 restricted transport, verified process ownership and native pre-start recovery fencing.

## Components

- `UnMotion.page` and `UnMotionSettings.page`: Unraid web-GUI pages.
- `api.php`: CSRF-protected web API dispatcher.
- `include/lib.php`: discovery, pairing, inventory, preflight, mapping and migration orchestration.
- `unmotion.js` / `unmotion.css`: client UI and status rendering.
- `rc.unmotion`: service setup, Avahi `_unmotion._tcp` advertisement and restart recovery.
- `unmotion-agent`: restricted remote operations used over paired SSH.
- `unmotion-ssh-gate`, `unmotion-transport` and `include/transport.php`: forced-command key identity, typed requests, exact destination reservations and confined storage/VM operations.
- `unmotion-process` and process-identity helpers: process-lifetime verification before signalling, including upgrade/removal guards.
- `unmotion-worker`: cold migration and Warm Move cutover worker.
- `unmotion-seed-worker`: prepare/update/resume/remove lifecycle for Warm Move seeds.
- `unmotion-replication-scheduler`: UTC-slot scheduler and interrupted-policy retry coordinator.
- `unmotion-replication-worker`: dedicated-ZFS snapshot, resume, verification and recovery-point commit worker.
- `unmotion-replication-state`: runtime progress writer that avoids high-frequency USB-boot writes.
- `unmotion-replication-lifecycle`: persistent libvirt event/reconciliation daemon for opportunistic host-state capture, managed starts and local fencing.
- `unmotion-replication-start-gate`: fail-closed validator and one-shot permit issuer for recovery-managed source starts.
- `qemu.d/50-unmotion-recovery`, `include/native-fence.php` and `unmotion-native-fence`: synchronous local pre-start admission, process-bound runtime records and proof that libvirt invokes the hook. The hook never calls libvirt or contacts peers.
- `unmotion-recovery-worker`: serialized coordinated evidence, activation, checkpoint-retry, stop and removal worker.
- `unmotion-notify-critical`: retried Unraid critical-notification helper used by recovery fencing.
- state/inspect/transform/cleanup helpers: job state, diagnostics, XML transformation and cleanup.

## Persistent and runtime state

Persistent configuration lives beneath `/boot/config/plugins/unmotion/`, including settings, peers, jobs, ownership, prepared-copy seed records, replication policies, incoming replica manifests and durable recovery authority/transaction journals. High-frequency replication and recovery progress lives beneath `/var/run/unmotion/`; per-run scratch data lives beneath `/var/lib/unmotion/`. Installed web and command files live under `/usr/local`, with the service script under `/etc/rc.d`.

Pairing uses a host ID as the durable identity and exchanges authorized SSH material reciprocally. Migration preflight builds a transfer plan, validates the destination, then launches a background worker whose JSON state and log are polled by the UI.

unMotion 0.4.2 advertises protocol `7` only. Older 0.4.1 peers used protocol `5` with recovery negotiation through `6` and are incompatible with the current wire protocol. Current pairing keys are restricted to typed, peer/VM/storage-bound operations, without a legacy remote-shell fallback. Both hosts must upgrade together; see [security and upgrade guidance](SECURITY-042.md).

Scheduled replication requires a separate replication capability. Its destination ZFS objects remain read-only and inert, separate from the libvirt VM inventory. Authenticated recovery messages bind both host identities, the exact VM/policy, boot IDs and a durable authority term. Coordinated activation creates separately owned ZFS clones. Protocol-7 recovery envelopes retain the existing schema-1 durable authority record; its historical `protocolVersion: 6` is not a wire-protocol negotiation. See [REPLICATION.md](REPLICATION.md) and [RECOVERY.md](RECOVERY.md).

Native recovery admission records live in `/run/unmotion/lifecycle/`, not on the boot device. The hook and authority-grant path coordinate through the recovery policy lock: an accepted but incomplete VM start blocks a destination grant. A small durable `native-managed` identity record ensures missing authority is not mistaken for an unmanaged VM. Source starts obtain a fresh peer authorization; recovered starts obtain a fresh source-fence grant. Runtime permits are single-use and never replace durable authority.

## Storage paths

- Dedicated ZFS zvol/dataset: snapshot plus compressed, resumable send/receive.
- Shared/encrypted/non-ZFS image: sparse `rsync` path.
- Warm Move: online seed, optional updates, then powered-off final delta/cutover.
- VM definition/state: transformed libvirt XML plus NVRAM/TPM data where present.
- Scheduled replication: dedicated ZFS storage only; exact non-recursive snapshots, resumable incrementals, destination-only historical retention and content-addressed host-state checkpoints.
