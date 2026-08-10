# Architecture

## Components

- `UnMotion.page` and `UnMotionSettings.page`: Unraid web-GUI pages.
- `api.php`: CSRF-protected web API dispatcher.
- `include/lib.php`: discovery, pairing, inventory, preflight, mapping and migration orchestration.
- `unmotion.js` / `unmotion.css`: client UI and status rendering.
- `rc.unmotion`: service setup, Avahi `_unmotion._tcp` advertisement and restart recovery.
- `unmotion-agent`: restricted remote operations used over paired SSH.
- `unmotion-worker`: cold migration and Warm Move cutover worker.
- `unmotion-seed-worker`: prepare/update/resume/remove lifecycle for Warm Move seeds.
- `unmotion-replication-scheduler`: UTC-slot scheduler and interrupted-policy retry coordinator.
- `unmotion-replication-worker`: dedicated-ZFS snapshot, resume, verification and recovery-point commit worker.
- `unmotion-replication-state`: runtime progress writer that avoids high-frequency USB-boot writes.
- state/inspect/transform/cleanup helpers: job state, diagnostics, XML transformation and cleanup.

## Persistent and runtime state

Persistent configuration lives beneath `/boot/config/plugins/unmotion/`, including settings, peers, jobs, ownership, prepared-copy seed records, replication policies and incoming replica manifests. High-frequency replication progress lives beneath `/var/run/unmotion/replications/`; per-run scratch data lives beneath `/var/lib/unmotion/replications/`. Installed web and command files live under `/usr/local`, with the service script under `/etc/rc.d`.

Peers advertise protocol `5` through Avahi. Pairing uses a host ID as the durable identity and exchanges authorized SSH material reciprocally. Migration preflight builds a transfer plan, validates the destination, then launches a background worker whose JSON state and log are polled by the UI.

Scheduled replication retains migration protocol `5` and requires a separate replication-protocol capability. Its destination ZFS objects are read-only and inert, are not merged with the libvirt VM inventory, and are exposed through a distinct incoming-replica inventory. See [REPLICATION.md](REPLICATION.md).

## Storage paths

- Dedicated ZFS zvol/dataset: snapshot plus compressed, resumable send/receive.
- Shared/encrypted/non-ZFS image: sparse `rsync` path.
- Warm Move: online seed, optional updates, then powered-off final delta/cutover.
- VM definition/state: transformed libvirt XML plus NVRAM/TPM data where present.
- Scheduled replication: dedicated ZFS storage only; exact non-recursive snapshots, resumable incrementals, destination-only historical retention and content-addressed host-state checkpoints.
