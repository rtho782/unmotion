# Beta9 qualification status

Author: Richard Skinner

**Lab-qualified, owner-approved and published as 0.5.0-beta9 on 2026-11-11**, using fixed installer ID 2026.11.11.01 and the exact candidate-3 artifacts below. The bounded checks passed; this is an experimental prerelease, not general-availability approval. Stable/Community Apps remain 0.4.9. Publication updated the beta feed and guides, not production installations. During testing, only the two authorized lab VMs on the outer host were restarted.

Scope: reviewed inactive live-copy cleanup, explicit native receive-completion proof, host-loss/reconciliation testing and release polish. DEV01 must remain Unraid 7.3.4; DEV02 remains 7.4.0. The [7.4.0 qualification](UNRAID-7.4-QUALIFICATION.md) tested published Beta8 and is separate evidence, not a Beta9 pass.

## Completed local checks

- 69 cleanup identity, legacy evidence, VM/kernel references, ZFS blocker and exact-unlink checks, including persistent dataset/GUID keys and replacement rejection. Temporary test files only; ZFS commands are mocked.
- 45 deletion-intent/receipt/replay, mixed-capability and short owner-lease checks in an isolated journal fixture. Lease expiry uses boot-bound monotonic time, not adjustable wall-clock time.
- 33 paired handler/callback/nonce/peer/owner binding checks with isolated host/process/transport adapters; no real SSH, libvirt or ZFS mutations.
- 53 reviewed migration RPC checks, including successful versus cancelled/failed/foreign/truncated native finish responses.
- 18 receiver-completion job/contract/boot/QEMU-lifetime checks.
- Real JavaScript cleanup review/submission tests cover immediate feedback, escaped names/paths, protected legacy objects, explicit deletion confirmation, double clicks, uncertain responses, receipts and cancellation/late-response isolation. Existing live and hand-back UI checks also pass.
- Existing local storage/capacity (77), ordinary hand-back (37), managed-upgrade (60), native-authority (16) and protected-POST method (52 routes) checks passed during development.
- Runtime PHP/page/helper syntax passed for 79 files plus the native hook; all plugin JavaScript syntax and 11 UI suites passed. Local live preflight (59), native fencing (33) and worker (6, Linux-only branch not run) checks also passed.

The complete privileged Linux `scripts/verify.sh 0.5.0-beta9` passed for all three private candidate builds, including native Plugin Manager FILE processing with mocked execution, real sparse/chroot transport, process namespaces and all syntax/UI suites. No missing-tool or skipped-test warnings appeared. The actual Beta9 candidate also passed the native processor's fresh/cache/corruption/offline/upgrade-failure/removal fixture. An earlier Windows-built artifact was rejected by the archive-mode guard and never installed; the Linux build resolves that platform-specific packaging issue without weakening the guard.

## Disposable-lab checks completed so far

Fresh powered-off snapshots of all four exact outer lab storage datasets precede destructive tests. DEV01 remains **7.3.4** and DEV02 **7.4.0**. Both licensing sticks and saved auto-attachment rules were verified by serial. No existing production VM or production plugin installation was changed.

- Real native Plugin Manager installation of candidate 1 on both hosts preserved host IDs, settings, pairing keys, existing Unraid hooks and boot-image hashes. Cached Beta9 restored after deliberately unclean lab restarts; native fence proof re-established through the normal daemon retry, without manually granting execution authority.
- A real Beta9-to-Beta8 peer check rejected cleanup before mutation when the optional capability was absent. Matching Beta9 peers advertised capability 1 over unchanged wire protocol 7.
- A new VM named `Squid Proxy Beta9 Cleanup` exercised raw, qcow2 and whole zvol disks together, UEFI and software TPM 2.0. Its normal DEV01-to-DEV02 live move completed with the new native finish receipt and five admitted storage/state identities on each peer.
- Held snapshots, open files, hardlinks, replaced inodes, loop-device use, foreign VM references and stale reviews blocked the complete cleanup plan before deletion. Current-owner cleanup was denied. All temporary test blockers were removed without deleting retained data.
- Source cleanup was interrupted after one durable deletion receipt, followed by actual outer DEV01 power loss. Cleanup did not resume automatically. Explicit reviewed retry completed all eight entries; fences, migration history and an unrelated sibling canary were unchanged. The owning VM stayed running, and its cleanup lease rejected a conflicting ownership move. Completion replay was idempotent.
- A live move of the existing Linux TPM guest from DEV02 to DEV01 preserved its guest boot ID, sealed-secret/public-key hashes and both data-disk canaries. Graceful guest shutdown was confirmed afterward.
- The final candidate also passed a protected Linux TPM cold boot on DEV02, recovering the original secret/public-key and both disk-canary hashes. Its subsequent DEV02-to-DEV01 live move preserved that same guest boot ID and all those hashes, with proof exit code 0. The return move on candidate 2 completed, but its asynchronous post-move proof was not collected before shutdown; no post-live proof is claimed for that particular run.
- Fresh ordinary cold and Warm Move cases passed with independent small raw/qcow2 disks and ISO transport. Warm preparation, controlled qcow2 growth, Update and final cutover passed. Received markers matched and raw files remained sparse.
- A legacy file/TPM job retained all five artifacts because no admitted identities were recorded. A legacy zvol identity was recognized, but its retained snapshot correctly prevented deletion.
- Actual source-host loss during native copying reconciled to a stopped source owner and an undefined aborted receiver, without authorizing the incomplete copy. Actual destination-host loss preserved the exact original running source QEMU; paired reconciliation left the receiver undefined/aborted. Disk canaries passed. Unresolved authority blocked upgrade and uninstall. Aborted receiver cleanup also completed with fences/history retained.
- At completed native transfer/source retirement, actual source-host loss left the receiver paused without automatic ownership/resume. Fresh paired reconciliation subsequently committed that exact receiver. Actual destination-host loss invalidated its previous-boot receipt; reconciliation aborted the lost receiver and restored stopped source ownership without automatically booting it. Both cases passed disk-canary and authorized owner stop/start checks. The private harness held only the verified source worker at the checkpoint; it did not alter host networking or pause unrelated VMs.
- Browser acceptance verified immediate loading feedback, disabled submission during checks, the exact five-artifact review, allocation/retention guidance and an enabled explicit confirmation only after validation. Completed cleanup displayed its read-only receipt with submission hidden and closed normally. Real deletion/retry used the backend workflow; the destructive browser click itself was not repeated. JavaScript tests cover its submission/double-click/uncertain-response paths.
- Both new cleanup POST routes rejected missing and incorrect CSRF tokens through native Unraid protection. Unraid 7.4.0 returned JSON HTTP 403; 7.3.4 rejected earlier with an empty HTTP 200 response, corroborated individually by native `webGUI` CSRF errors. Authenticated status and valid protected read-only POSTs succeeded on both. No CSRF behavior was changed to make the test pass.
- A new independent 16 MiB raw dataset completed two replication generations with retention of one point, explicit paired end/delete, and source canary preservation. Recreating the same VM/destination produced a separate policy/incarnation, completed a fresh generation and ended/deleted normally. History and retirement receipts remain; recovery activation was not performed for this no-agent fixture.

## Final candidate and reboot regression

Candidate 3 contains the kernel-reference wording correction and the ZFS filesystem-identity fix found during acceptance. The original device/inode binding safely refused cleanup after an import changed a ZFS filesystem's transient device number. New admissions now use the exact dataset name/GUID plus inode. Old unproven identities are not silently upgraded or adopted; non-ZFS filesystem identities retain strict device/inode checking.

Candidate 3 was installed on both stopped labs using guarded same-version package reinstall, then verified against every packaged runtime file. All 108 runtime files matched on both hosts, with runtime-manifest SHA-256 `e5ef9c97903f4d3163f4dcd1fc7a5cea78d31d1bdac132241c876b631719e1eb`. OS images, local settings, host IDs, pairing keys and existing Unraid hooks matched the private pre-Beta9 baseline; only expected peer capability/version/contact cache fields refreshed. Native proof, licensing and arrays were ready. An active background worker correctly blocked one install attempt; retry succeeded after it finished, without bypassing the guard.

The final privileged verification log has SHA-256 `a644225a76457952c48a55a7f731117c6a13a3c2e0d385e74437987c96ac9cd7`. Earlier candidates and their logs remain private for comparison; do not substitute their artifacts for candidate 3.

DEV02 then restored the exact cached candidate after another real outer restart. A fresh 64 MiB VM with spaces in its name completed live transfer. Controlled remount of DEV02's idle `domains` filesystem changed its kernel device number **42 → 43**, preserving dataset GUID and inode. Explicit reviewed cleanup completed, retaining the owning peer's verified disk, start fences and job history. The exact empty numbering-probe dataset was removed afterward; no existing dataset was destroyed. Cleanup never resumed automatically on boot.

Published artifacts (exact qualified candidate-3 bytes):

| Artifact | SHA-256 |
| --- | --- |
| `unmotion-0.5.0_beta9-noarch-1.txz` | `821ebb28a8c1a47d85d1bbc265e321b1ce90cd56c6a2dd4903efb253c0945906` |
| `unmotion-0.5.0-beta9.plg` | `0e142ddb248862f5d4dcdb9c635e5cff6ccc185dedf80df64877709dbff7f162` |

The copy/handover fault matrix and mixed raw/qcow2/zvol/UEFI/TPM checks used candidate 1. Candidate 2 changed reference-blocker wording only; candidate 3 additionally changes ZFS file-cleanup identity and its live FD alias check. Candidate 3's full suite, normal live move and reboot/renumbered cleanup were rerun. This distinguishes inherited evidence from tests actually repeated on the final bytes.

At final handoff both lab arrays were licensed and started, native fencing was ready, and **all inner lab VMs were stopped**. The protected Linux guest shut down gracefully; its guest agent reported disappearing during shutdown, so completion was confirmed from actual libvirt state rather than treating the agent response as proof. New test replication cycles were terminal, not left scheduled. Only explicitly reviewed inactive test data was deleted; current owning copies, unproven/blocked legacy data, fences, history and outer snapshots remain.

## Review and remaining scope

- The owner approved prerelease publication after reviewing the lab results. Source and companion guides describe Beta9 availability; the stable feed and CA template remain stable-facing. Release downloads, beta-feed identity and stable CA URL equality are verified during publication.
- No separate physical-host migration or new Windows/BitLocker qualification was performed. No new automatic recovery or authority-system handover is implied by cleanup.

A disposable VM between the separate physical production hosts would provide additional hardware evidence, but is **not performed or authorized for mutation by this lab task**. Under the current same/newer-generation rule, the natural live direction is Tacgnol (Skylake) to Longcat (Cascade Lake); a powered-off ordinary return is a separate operation. DEV01 to Tacgnol additionally exercises nested-to-bare-metal migration but is not identical to two bare-metal hosts. Existing production VMs must remain out of scope.

Windows/active BitLocker coverage remains the inherited Beta7/Beta8 qualification; Windows live migration, protected Windows boots and every physical failure combination were not rerun on Beta9. These are bounded lab results, not proof against every power-loss scenario, production certification or general-availability approval.

## Outer-host health observation

Following the owner's CPU0 DIMM5 replacement and Longcat's 7.4.0 boot at 11:48 UTC, current-boot kernel/syslog checks through **13:09 UTC** showed no new matching MCE, EDAC, uncorrected-memory, DIMM-error or CMCI messages, including during lab transfers and restarts. This is about **81 minutes of observed uptime**, not a dedicated memory stress test or proof that the hardware fault can never recur. Historical pre-repair errors are not counted as new faults.
