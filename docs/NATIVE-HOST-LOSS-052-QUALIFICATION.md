# Native live migration: host-loss and reboot qualification

Author: Richard Skinner. Recorded 2026-11-14 for **0.5.2-beta4**.

## Scope

Testing used disposable guests on DEV01 **Unraid 7.3.4** and DEV02 **7.4.0**, on separate physical hosts. DEV01's OS was not upgraded. No production host, production guest, outer-hypervisor control or Linux build VM was accessed. Existing cold fixture backups, retained outer-lab recovery copies and snapshots were preserved.

Host loss here means an abrupt restart of the nested Unraid lab OS, skipping its normal shutdown and final filesystem sync. The test intent itself was synced first. This is a real loss of that host's processes, RAM, SSH sessions and runtime files; it is **not** a physical power-cut or storage-controller durability certification.

## Results

| Actual case | Result |
| --- | --- |
| Clean DEV02 reboot with all guests off | The unpublished candidate loaded from its verified package cache; settings, ownership and native start fences persisted. No guest autostarted. |
| Clean DEV01 reboot with all guests off | Same result, retaining both running and staged Unraid **7.3.4**. |
| Receiver forcibly restarted during incomplete native disk copy | Native migration failed and required review while the receiver was unavailable. After its return, authenticated reconciliation aborted/unregistered only that incoming definition and restored sole source ownership. The source retained its original kernel boot, manually started writer/token, advancing writes and disk canaries. Partial storage and history remained retained. |
| Source forcibly restarted after native resume and synced success, before ownership bookkeeping | An exact lifetime-verified worker was paused at the durable-success boundary, then its host was restarted. The receiver kept its original guest boot and writer. After source boot, authenticated reconciliation used durable success plus fresh absence/ownership checks to complete destination ownership without restarting/resuming the guest. |
| New file-disk round trip after both interruptions | Both directions completed, retaining the guest boot, writer and canaries; temporary credentials were withdrawn. |
| New zvol round trip with AES-256-GCM | Both directions completed after the host restarts, preserving guest continuity and disk canaries. Zvols remained independent zvols. |
| Windows protected boot after host restarts | Server 2025 Core booted with software TPM identity, active TPM-only BitLocker, protectors and OS/data canaries unchanged. Its manual-only writer did not autostart. The VM was then shut down normally. |
| Complete isolated verification suites | Passed again on both labs, with PHP/Bash/Node/Go and privileged transport/fencing checks; no missing-tool pass was substituted. |

Both labs deliberately retain their saved manual array-start setting. After each reboot the licensed array was started normally, without enabling any VM autostart. Native fence verification initially reported not-ready while VM Manager/background verification settled, then proved pre-QEMU rejection automatically; migration admission was withheld until ready. The cached-package tests kept networking connected: they do not claim a NIC-disconnected/offline-network test.

Two private-controller issues were handled without changing product safety: a malformed test argument was rejected before any reboot, and a local SSH client waiting on the deliberately severed connection was closed after verifying the durable reboot receipt. Neither fault was repeated blindly. No authority record was fabricated, no fence was removed, and no retained VM storage was restored over current data to manufacture a pass.

## Exact candidate and inherited evidence

The package was **not rebuilt** for publication:

- Application: `0.5.2-beta4`; fixed installer ID: `2026.11.13.03`.
- TXZ SHA-256: `02f28276b0411a1d7f6a1a65dafd615aee628777cc793673247ea9b46ecc3ed0`.
- PLG SHA-256: `96c9029662f98009e16135a4d39e253b62dd654462dea86962509e56104b51d8`.

The current source rootfs matched all 116 files in the qualified source archive byte-for-byte. Packaging adds the licensed native binary/support files; final installed-runtime audits check 118 files. The [native-mode qualification](NATIVE-TRUSTED-MIGRATION.md) separately identifies earlier core-build measurements and inherited Windows live/BitLocker, cancellation, worker-loss, ordinary Cold/Warm Move and replication coverage. Those earlier tests are not described as freshly rerun host-loss tests.

Final audits passed on both labs: all **118 installed runtime files** matched the candidate, all guests were off, both arrays/native fences were ready, and each host had two normal daemons with no transfer workers, temporary SSH clients, lease gates, custom relays or display samplers. All **21 native job records** were settled. Available current-boot key records were revoked/quiesced (five and four respectively); earlier runtime key records disappeared normally on reboot, so these counts are not a claim that old runtime files survived. No native authorization lines or source private keys remained. The 19/17 unrelated VM definitions/states, 19/24 protected whole-file hashes and pairing identities/credentials were unchanged. Only the two new settings and expected peer observation fields differed from the earlier baseline. Both labs retain AES-128 and explicit native trust, with file/zvol owners off on DEV01 and the Windows owner off on DEV02. Retained data, backups, authority and history remain intact.

## Operational limits

Use **Reconcile** from the source only after its worker and native libvirt jobs have stopped, with both peers reachable. A migration error while the peer is down does not authorize another boot. Reconciliation may preserve a running original source, or complete an already proven destination, without automatically booting a stopped VM.

There is deliberately **no automatic failover**. Losing the source before provable completion, losing both running copies, or rebooting an uncommitted receiver can leave the VM fenced for operator review. Missing/old-boot/mismatched completion evidence is rejected by regression tests; this run did not physically inject every such combination or the exact subsecond RAM-handover window. The two actual forced-restart cases above are the bounded new-mode host-loss qualification, not an exhaustive hardware/failure matrix. Do not delete authority/history or manually boot a retained copy to bypass a block.

Native trust temporarily grants administrator-equivalent libvirt/forwarding access. Key expiry/removal and exact session termination are operational cleanup, not protection from a malicious privileged peer. Permanent pairing restrictions and strict host-key pins remain. Review the [trust model and setup](NATIVE-TRUSTED-MIGRATION.md) before enabling it on both hosts.

This run did not qualify a real mixed-plugin-version pair or a fresh upgrade directly from public 0.5.0; normal private-candidate upgrades and cached reboots were exercised. Exact-version/capability rejection is covered by the suites. Stop managed VMs and finish/reconcile work before upgrading both live peers together. Existing device/storage restrictions remain, including the writeback-cache gate, no passthrough, no attached ISOs and no AMD nested live migration. TPM evidence is Server 2025 Core/TPM-only BitLocker, not every Windows/PCR/PIN profile.
