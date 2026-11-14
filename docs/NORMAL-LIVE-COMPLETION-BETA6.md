# Normal VM completion after a live move

Author: Richard Skinner. Available in **[stable 0.5.2](https://github.com/rtho782/unmotion/releases/tag/0.5.2)** through Community Apps and both feeds, displayed as `2026.11.14.03-0.5.2`. This promotes the qualified beta6 logic unchanged apart from release metadata. The historical filename and beta6 artifact/test records below are retained; see [stable release scope and later physical-host measurements](RELEASE-0.5.2.md).

## User workflow

After confirmed handover, the destination is a normal VM. There is no **Return to ordinary workflows** step, VM shutdown or reboot merely to enable its controls. Cold/Warm Move, CPU editing, cloning, replication, recovery configuration and native VM controls use their ordinary requirements. For example, Cold Move still needs the guest powered off, CPU changes take effect at the next full boot and TPM guests still cannot be cloned. These are not restrictions caused by a previous live migration.

The source's captured autostart selection is restored on the destination. A normal destination has no active live-owner marker/admission and does not require its former peer to authorize a native start. A small private completion receipt supports provenance, cleanup and future return transfers; it is not a native start permit or an ongoing hardware contract. If recovery is deliberately armed later, recovery's normal autostart/permit rules apply separately.

New successful moves automatically clean up the verified obsolete source disks, NVRAM and TPM after the source definition is unregistered and disposable transfer sessions have closed. There is no hours-long retention timer. The destination keeps running during cleanup. Native start fences on non-owning hosts and migration history remain, preventing stale-copy starts without keeping full disk copies forever.

## Cleanup outcomes

Handover success and cleanup progress are distinct. An unavailable peer, changed identity/reference, foreign ZFS snapshots/holds, dependent clones or uncertain ownership can block deletion. The job reports **Cleanup BLOCKED** with the reason and a review/retry control; it does not stop the destination, repeat the migration or pretend data was removed. Exact source unlink/non-recursive zvol destruction preserves shared datasets and unrelated contents. Empty shared image-parent directories may remain.

Failed, cancelled, partial and unresolved receives are not automatically discarded. Reconcile ownership first and explicitly review their cleanup. Older jobs keep their existing identity limitations: filenames alone do not authorize deletion. Already-settled older owners can normalize in the background when both upgraded peers prove the state; this does not retroactively delete unknown legacy artifacts. **Retry completion** is available for a blocked normal-completion step and does not restart the guest.

If libvirt already removed an incoming temporary TPM directory, cleanup records its absence and can remove the other verified objects. A reappearing path blocks cleanup rather than authorizing deletion of its replacement. A proven never-started Warm Move can instead archive just its failed record and logs, without deleting VM storage or contacting the peer for storage removal.

## Crash and compatibility boundaries

Owner release uses the native VM lock and a synced intent before removing either exact old owner record. A retry recognizes only the saved record hashes; a newer migration/authority cannot be removed by a stale retry. A crash between unlinks fails closed until completion repairs the exact pending pair. No running process is adopted. Source retirement and native completion proof remain prerequisites, and non-owner fences are never cleanup targets.

Normal Cold/Warm return transfers require optional `liveNormalVersion: 1` on both peers. They install temporary handover authority only during cutover, retire the source before granting destination ownership and release the committed destination back to normal controls. Wire protocol 7 and unrelated operation capability gates remain unchanged. Live migration still needs matching application versions.

A recovery activation on a previously retired host must pass its full activation/disk/grant/one-shot/native-process checks. It may not borrow an old live copy's data or remove its fence. The old UUID without the exact recovery activation remains blocked.

The installer pins a reviewed beta5-to-beta6 transition at both ends of the complete eight-file native fencing graph. Existing original admissions and current libvirt/QEMU lifetimes are still required for a running-owner upgrade. A later unknown graph, locally changed code, missing/corrupt proof, unresolved work or active recovery-managed guests does not inherit this exception.

## Qualification results — 2026-11-14

The full isolated suites passed on DEV01 **7.3.4** and DEV02 **7.4.0**. The released package contains the exact 120 qualified runtime files. Qualification includes:

- Real beta5 upgrades with Linux file/zvol owners and a Windows TPM/BitLocker owner still running: original QEMU lifetimes, guest boots, manual writers and protection survived; unknown fencing changes were rejected before replacement. Older settled owners normalized without restarting, and all three old-copy starts remained blocked before QEMU.
- Bidirectional file and zvol live moves with guest continuity and automatic exact source deletion. Historical backup snapshots deliberately blocked one old zvol copy; the successful handover and normal destination remained available. Fresh obsolete zvol copies were actually destroyed, not merely hidden.
- Bidirectional Windows Server 2025 Core/software TPM live moves, unchanged TPM identity, active BitLocker/protectors/canaries, automatic removal of fresh obsolete disks/NVRAM/TPM, cancellation retaining partial disks, reviewed partial cleanup and a protected full stop/start as a normal VM. These Windows checks used earlier task candidates; the final corrections do not change their live transport or TPM handover engine.
- Normal CPU-edit and clone admission, actual autostart preservation and native rename, Cold Move recovery after an interrupted normal release, and a fresh Warm Move return to a previously fenced host. Both file-disk canaries survived. A fresh final-candidate live move after those ordinary workflows also passed.
- Explicit replication configuration, guest-agent-informed incremental points, recovery arming/authorized source startup, coordinated recovery on the previously retired peer, intact recovered disk canaries and source native-start denial. The old live fence remained intact while the exact recovery activation passed its separate native permits. The activation was stopped and removed, and the test replication retired through paired exact cleanup.
- Exact crash/replay/newer-authority rejection, changed-owner-storage cleanup refusal, missing/reappearing host state, same-boot never-started archival, complete-graph upgrade transitions and mixed-peer rejection in isolated checks. No new physical host-loss/reboot campaign or fresh visual-browser qualification is claimed for this task.

The recovery experiment used IPv4 because the labs' private guest IPv6 subnet is not routed between hosts; the unreachable-address check was not bypassed. Test teardown restored genuine saved pre-test recovery metadata only after both copies were stopped and the exact activation removed, preserving test history and old fences. **This is lab rollback, not a new automated failback/source-authority restoration feature.** Existing recovery/failback limitations remain.

Published TXZ SHA-256: `67a5c114233134d4ef33ad78bc6f0c9e1a8c89e7728b3504949fd2d4b3b96119`; PLG SHA-256: `7a7a055225c6cadba09d9e2e7fd70b56cb4c968061c45c5e52e1c0ebf57500fa`. The TXZ is the exact qualified package, without rebuilding. Only descriptive installer release notes were corrected for publication; all installer commands, attributes, artifact URL/checksum and fencing contracts are identical to the qualified PLG (`b008c1d1d9693b21afcd283867c62786ceaafc68f6b7c38d0c5c3e111a51ebce`). The native bulk transport, wire protocol and live handover ABI remain unchanged. Production hosts were not contacted or modified.

Final read-only audits passed on both labs: all guests stopped, the file/zvol owners back on DEV01 and Windows owner on DEV02, 19/17 unrelated VM definitions/states unchanged, protected OS/settings/hooks/pairing credentials preserved, all 120 installed runtime files exact, no active workers or disposable transfer credentials, and both arrays/native fences ready. Neither lab rebooted; DEV01 remains 7.3.4. Historical backup snapshots, small fences/history and private QA backups were deliberately preserved.
