# Beta8 qualification — 0.5.0-beta8

Author: Richard Skinner

Scope: native live zvol-to-zvol/mixed storage, capacity/reservations and separate disk/RAM progress. Reviewed and authorized for beta publication on 2026-11-11. Stable and Community Apps remain 0.4.9; this release is available only through the opt-in beta feed.

## Qualification evidence

- Fresh powered-off snapshots cover the four exact storage datasets of DEV01/DEV02. Only these outer lab VMs were stopped/restarted. Both licensing sticks and arrays were checked after boot.
- The final candidate passed a privileged isolated Linux verification/build, including existing native fencing/transport/workflow tests and PHP/Bash/Node syntax. Its 77 targeted Beta8 assertions cover storage contracts, GUIDs, geometry, capacity overflow/pooling, ZFS dry-run parsing, provisioning fencing/rollback, authenticated unreserved-attempt recovery, mixed live capabilities and raw progress fields.
- Both labs installed the qualified candidate before publication; settings, host IDs, reciprocal pairing, authorized keys and the existing Unraid hook were hash-preserved. Superseded recognized Beta7 package downloads were pruned by the normal hook; these can be downloaded again.
- Fresh native hook probes denied QEMU startup as intended on both hosts.
- DEV01's independent 64MiB non-OS zvol probe passed an authorized native start. Read-only preflight was rejected. Renaming the original volume away, and creating a different GUID under the same name, each caused pre-QEMU rejection. The original volume was restored, freshly attested and started successfully. All probe volumes were retained.
- A quota-limited destination parent was rejected by the real capacity planner without creating a target volume or changing persistent settings.
- Browser observation confirmed separate disk/RAM statistics during a real transfer and a readable capacity dialog showing disk kinds, pool budget/free space/headroom and reservation/retention policy. The old expired login stopped requests correctly; fresh lab logins restored the pages.
- A saved foreign VM definition referencing the same writable zvol through a literal-space alias blocked preflight. Only that temporary definition was removed afterward; its XML and all storage were retained.
- An Ubuntu UEFI guest with a 12GiB root zvol, 64MiB data zvol and 64MiB qcow2 disk completed live moves in both directions, with continuous guest writes. Its boot ID was unchanged, counters advanced from 54 to 5,197, and all three canaries matched after each move. A subsequent full cold boot on the final package changed the boot ID, preserved counters at 5,198 and matched every canary. Guest filesystems were mounted inside the guest, not on the host.
- On a separate non-OS 1GiB zvol probe, cancellation and loss of the exact owned disk relay each preserved the original running source QEMU/OWNER and left the destination absent/ABORTED. A following move completed; recreating the retired source definition did not authorize a start, which was rejected before QEMU. The destination passed stop/start/stop. Every source/partial volume remains retained.
- A separate disposable zvol clone was destroyed and recreated from its held snapshot. The kernel reused its device path and device number, but changed `diskseq`; the stale identity proof was rejected and the recreated data hash matched. Only that exact clone was replaced; its origin, held snapshot and all VM disks were retained.
- Ordinary Cold Move and Warm Move also passed on separate disposable probes. Warm Update and cutover preserved final raw/qcow2 writes even when file size and timestamp were unchanged; the qcow2 virtual size stayed fixed and the sparse raw transfer remained sparsely allocated.
- Windows Server 2025 Core, with two zvols and active BitLocker, completed live migration in both directions and a protected full cold boot at each destination. Each live move preserved boot time and the in-memory heartbeat; TPM endorsement identity and both disk canaries matched throughout, with protection on and no recovery-key entry or suspension. The outward move used candidate4; the return used candidate6, whose data-plane code is unchanged. Temporarily quieted Windows update services were restored successfully, and the guest was gracefully powered off.
- The six saved Windows stages also matched the hashes of both BitLocker protector identities, not just their types. Protection remained on, fully encrypted and unlocked in every stage.
- Nine private lab fault-injection cases exercised source worker loss before reservation, a wrong token, a lost abort acknowledgement, paused/stopped sources, interrupted unreserved-cancellation journals before/after the native fence, and interrupted receiver provisioning before/after its early fence and after volume creation. Real authenticated paired callbacks and Reconcile preserved the original running QEMU/owner, resumed the same paused process, never booted a stopped source and left the receiver absent/ABORTED. The exact partial zvol retained its GUID and geometry. These were controlled journal/crash-point injections, not nine physical host power failures.
- All 105 packaged files match normalized source bytes; all 104 installed runtime files match on both hosts. Cached TXZ and installed descriptor hashes also match, with native fencing proven, arrays started and both Starter licenses valid.
- After DEV02's outer-VM restart, the cached candidate restored without a new release download. Settings, pairing keys, host identity, existing Unraid hook and durable live authority were unchanged. The lifecycle daemon automatically produced fresh boot-bound zvol evidence and re-proved the native fence after VM Manager started. The retained Linux mixed-disk guest then booted with all three canaries and counters (5,198) intact. A recreated aborted non-OS receiver definition was denied before QEMU; only that temporary definition was removed, retaining its XML and disks. Fixtures were powered off afterward.

## Findings corrected during development

Encoded source zvol aliases needed the local encoded-path capability passed to the structural XML validator. A regression test now covers this review path.

Explicit `refreservation=auto` during volume creation produced an unexpected out-of-space failure on DEV01 despite ample free space. A 64MiB probe reproduced it; ordinary non-sparse creation computed a valid full reservation. Provisioning uses non-sparse creation, a fresh no-write ZFS estimate and verification of the resulting reservation. The failed migration retained the running source owner and no destination VM was created.

An attempt rejected before the receiver reservation was journalled could previously strand reconciliation when that host already retained an older non-owner fence. The final candidate adds authenticated, fresh-callback abandonment of genuinely unreserved attempts, with resumable cancellation journalling. It cannot replace an owner, recovery record, defined VM or real receiver; it creates no storage or execution permit. The paired fault-injection cases above passed.

## Published artifact identity

Application `0.5.0-beta8`, installer ID `2026.11.10.02-0.5.0-beta8`. Publication reuses the exact qualified candidate6 artifacts without rebuilding them; the fixed installer ID was allocated during qualification.

- TXZ SHA-256: `f6a1647e6b75717aefa2885c3fa1ced16c81adb76b64b4411d820aa156f6e52e`.
- PLG SHA-256: `583c43143aca51053071f4d529581df19c5bfc6d1c712c740b968c83646f0ebf`.

## Qualification limits and review

DEV02's normal reboot and array-stop paths stalled during persistence testing, with PID 1 in uninterruptible kernel I/O and libvirt defunct. After confirming no guest QEMU remained and syncing storage, a kernel restart was attempted; connectivity did not return until the exact outer DEV02 VM was force-stopped/restarted. Its correct licensing USB attachment was verified before and after. Pool health, plugin/settings/fence persistence and guest data checks passed afterward. This is an **unclean restart qualification**, not a successful graceful host-shutdown test; the underlying lab kernel hang was not root-caused. No other Longcat VM or host configuration was changed.

Linux bidirectional mixed-disk moves and the outward Windows move used candidate4. Candidate6 retains the same data-plane code and adds the qualified early-reservation reconciliation fix; it passed the Windows return, nine authenticated fault injections and restart persistence. The full isolated suite includes legacy workflows, but these results are not a claim that every guest OS, disk size, ZFS layout or hardware fault has been qualified. AMD nested live migration, arbitrary block devices and native ZFS-encrypted zvols remain excluded. Source/partial storage is retained; only the exact disposable clone described above was destroyed/recreated from its held snapshot.

Private evidence-collection issues (setup output mixing and a delayed scheduled heartbeat) were corrected; they did not require a guest protection bypass. The owner reviewed the results and authorized publication on 2026-11-11. The source documentation, beta feed and companion Community Apps guide accompany the release. Stable remains 0.4.9; production installations are left for the owner to update through Unraid's Plugins tab.

The progress parser follows [libvirt 12.2's raw job-stat output](https://github.com/libvirt/libvirt/blob/v12.2.0/tools/virsh-domain.c). Full versus sparse volume policy follows [OpenZFS volume provisioning](https://openzfs.github.io/openzfs-docs/Basic%20Concepts/Datasets/ZVOLs.html); availability cannot protect against every concurrent external allocator.
