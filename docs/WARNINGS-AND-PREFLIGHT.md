# Notices and preflight in 0.4.1

Author: Richard Skinner

Normal behaviour belongs in transfer details. Actionable problems remain warnings, and irreversible deletion or recovery-ownership changes require explicit confirmation. Warnings are not repeated in an additional generic "risky migration" dialog.

## Warm Move and cloning

Software TPM presence alone does not require confirmation. Preparation copies storage only; cutover still requires source shutdown and the final disk, TPM and UEFI transfer before destination start. A prepared seed is not a bootable recovery point. Replication's safe/best-effort TPM warnings remain unchanged.

Preparation and seed update preflights retain identity, storage ownership/conflict, capacity, base-snapshot GUID and read-only checks. CPU/RAM startup availability is guidance at this stage, not a blocker. Cold migration and cutover recheck startup resources; the migration endpoint forces the appropriate start-capable mode and the worker retains its resource gate. PCI passthrough and unsupported disk layouts remain blocked.

Supported sparse rsync, shared-dataset file copies, resolved FUSE paths, ISO retention and sparse-raw conversion appear as neutral details. Important changes such as guest identity customization, NIC disconnection, USB handling and incompatible pinning remain explained. Creating a clone is confirmed by the configuration dialog's **Create clone** button, without a second generic prompt.

## Host health

The host health dashboard continues to show host-wide issues. Migration preflight filters known issues using the plan's destination datasets and paths, including copied/retained ISOs and custom NVRAM. A missing unused image/ISO directory, a fault in a provably unused pool or an unrelated crashed VM does not impede the selected transfer. Relevant pool faults, data errors, libvirt/pairing problems and unknown critical issues remain blockers.

Ambiguous/FUSE destination paths and missing context from older peers retain conservative storage checks. Only proven unrelated dependencies are excluded. Ordinary scrub activity is informational; resilver activity remains a warning. Timestamped OOM entries expire after one hour; unparseable or future timestamps are not presented as "recent".

## Recovery controls and history

Failback prerequisite checks, renewal of the same coordinated claim and retry of the same durable hold acknowledgement do not ask for redundant confirmation. Authentication, CSRF, authority, identity and freshness validation are unchanged. These actions do not add automatic failover or failback transfer.

Failed seeds with verifiable evidence that storage operations never began offer **Archive failed record**. The request is explicitly archive-only and revalidated under locks; a stale page cannot authorize deletion. Logs are retained in the existing archived-seeds directory. Other seeds retain explicit prepared-storage deletion confirmation and the existing exact-target cleanup rules.

## Concurrent cutovers

Prepared-copy actions are disabled while a migration/clone job owns that VM, including queued and attention-required jobs. The backend enforces the same rule under a short admission lock. ISO choices are omitted when the VM has no attached ISO.

Independent Warm Move cutovers use a shared compatibility lock when both peers support the destination-start gate. Existing seed and active-job storage reservations must not overlap. Pending same-peer cutovers reserve their requested RAM at admission; destination starts then serialize a fresh RAM/state check and libvirt start. Disk and TPM transfers for independent VMs remain concurrent.

Cold moves, destination overwrite, copying attached ISOs and older-peer cutovers retain exclusive access and wait cancellably instead of failing due to contention. Same-VM/storage conflicts and insufficient unreserved RAM remain real blockers; waiting does not make conflicting storage safe. Reservations are source-local, not a distributed cross-source storage allocator.

Wire protocol versions are unchanged. New notice fields are additive, and phase-aware preflight runs on the initiating host. Older sources retain their existing warnings and stricter preparation checks until upgraded.
