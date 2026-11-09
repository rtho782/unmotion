# Beta4 CPU configuration and nested virtualization qualification

Author: Richard Skinner

Test date: 9 November 2026. Stable remains 0.4.9. Beta4 retains wire protocol 7 and requires matching Beta4 peers for live migration.

## Defect and fix

Two real CPU-configuration batches stopped after the first successful persistent definition because libvirt moved `<topology>` ahead of `<feature>` nodes. All CPU values and the visible non-CPU XML matched; the old whole-document canonical hash incorrectly treated child ordering as a substantive change.

Verification now normalizes only known direct CPU-child ordering. It retains every attribute, feature policy, topology/NUMA value, duplicate and all non-CPU content. Secure persistent XML reads preserve console credentials during transformation, backup and verification. Changes still take effect only at the next full stop/start; no production guest was restarted for this repair.

Existing VMX/SVM exposure is preserved, including implicit host-passthrough/host-model exposure when KVM nesting is enabled. Explicit opt-outs and required nested subfeatures are retained. Each augmented guest CPU is freshly compared locally; unsupported requirements are skipped, not stripped. Cached host reports and their compatibility hashes are unchanged. A shared base-mask suggestion does not itself qualify guest-specific nested requirements on a destination.

## Automated checks

The complete `scripts/verify.sh` suite passed on the isolated Linux development host with PHP, Bash and Node. It includes **108 CPU-configuration assertions** and **49 live-preflight checks**, plus existing pairing, cache, concurrency, firmware/media, process-lifetime, native fencing, replication, migration, packaging and UI tests.

New cases cover libvirt topology reordering, idempotent reapplication, continued rejection of real feature/topology/non-CPU mutations, secure XML on every read, preserved console passwords, explicit VMX policies, implicit host exposure, AMD SVM control flow, disabled nesting, unavailable augmented contracts and AMD nested-live rejection. Windows PHP syntax/portable checks ran; Linux filesystem/process tests were run on Linux rather than counted as Windows passes.

## Disposable-host checks

DEV01 (Unraid 7.3.3) and DEV02 (7.3.2) use libvirt 12.2.0 and QEMU 10.2.3. Both were upgraded with all guests stopped. Original settings, CPU observations, pair keys, saved XMLs and Unraid's parent hook were checked unchanged. Exact snapshots of the four verified outer lab storage datasets were taken before testing; no recursive snapshot/send/delete was used.

Independent BIOS qcow2 fixtures named **Squid Proxy CPU Beta4 A/B** have two vCPUs, 1 GiB RAM, explicit topology, a protected VNC console and no NIC. Both started with host-passthrough and were updated while running to `Skylake-Server-v5` with VMX preserved. Both real libvirt batches completed, repeat previews reported unchanged, and console authentication survived. The guests were then deliberately stopped/started to activate the named CPU.

Inside each guest, a small Python test opened `/dev/kvm`, created a VM/vCPU and repeatedly executed a real-mode HLT instruction using KVM. It recorded a numbered, fsynced execution log. This proves actual nested hardware execution, not just the presence of a CPUID flag.

The A fixture was live-migrated DEV01 → DEV02 → DEV01 while the nested VM continued executing. Both moves completed with unchanged outer-guest boot ID and workload PID, contiguous execution counters, fresh post-migration records and successful KVM exits. Ordinary non-nested qcow2 live migration also completed after the full-CPU validation change. Mixed Beta4/Beta3 live preflight was rejected before reservation/mutation through an existing pairing.

Read-only physical-host previews validated the augmented Skylake CPU against the actual VM machine versions. Two explicitly affected saved definitions were repaired from their original CPU provenance and then given the validated nested-preserving mode. Repairs retained current secure non-CPU XML, kept backups and did not restart running production QEMU processes. Other production definitions were not silently included in that repair.

## Limits

The nested execution test is a minimal KVM workload, not a complete nested OS or Windows Hyper-V/VBS qualification. Physical AMD was unavailable: SVM preservation/gating has synthetic coverage, not a physical AMD boot claim. The [kernel documents restrictions on live migration with active AMD nested guests](https://docs.kernel.org/virt/kvm/x86/running-nested-guests.html); this beta blocks SVM-enabled live migration rather than guessing whether an L2 workload is active.

Changing a parent VM's CPU may also hide features needed by its nested guests; their own CPU requirements need review. KVM module settings are never changed automatically. Initial live limits remain BIOS, one independent file disk, matching qualified software, same/newer CPU generation/vendor, no TPM/passthrough/ISOs and no recovery-managed guests. Live-managed guests still lack a safe hand-back to legacy workflows. See [live-migration requirements](LIVE-MIGRATION-BETA.md) and the broader [Beta3 interruption/fencing tests](TESTING-LIVE-BETA3.md).
