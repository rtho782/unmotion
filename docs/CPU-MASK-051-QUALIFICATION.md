# CPU-mask eligibility correction — earlier development qualification

Author: Richard Skinner

This record describes the earlier private **0.5.1** test build. Its CPU-eligibility correction is included in published **0.5.2-beta4**; stable remains 0.5.0. The initial same-or-newer physical-generation rule in 0.5.0 is too restrictive for an active common CPU mask: a newer source host can expose exactly the older ABI that the destination accepts. Version/hash requirements below identify the historical test build, not an installer to use now.

The fix removes physical-family ranking from both source preflight and receiver reservation. It retains an explicit supported same-vendor boundary, fresh complete active/pending guest CPU comparison, destination hypervisor CPU comparison and nested-feature checks. Host names/models and cached CPU suggestions do not authorize migration. Unsupported CPU features, cross-vendor live moves, AMD SVM live migration, mismatched machine/software/firmware/storage and unresolved ownership still fail closed. Protocol 7/live ABI4/native-data1 are unchanged; exact matching 0.5.1 is required for live moves.

Automated regression coverage includes older/newer/unranked physical model names, malformed/unsupported/cross vendors, ordering of fresh receiver validation before the vendor gate in both call sites, and existing nested/fencing checks. Both full verification suites passed on DEV01 7.3.4/DEV02 7.4.0. A real lab receiver accepted the actual guest CPU and rejected an injected unsupported avx512fp16 requirement without modifying a VM definition or creating a transfer.

Normal guarded installation preserved all original VM definitions/process states and protected settings. Actual i440fx file-backed and Q35 zvol Linux moves preserved the boot, original writer and both canaries. A Windows Server 2025 Core software-TPM/active-BitLocker move preserved its kernel boot, original manual-only writer, TPM identity, protectors and both canaries; a subsequent protected full stop/start also passed. A transient heartbeat-file sharing collision and one lost guest-agent shutdown acknowledgement were test-harness observations, resolved through a repeated read and fresh proof that the VM was already off, without forcing it or changing migration logic.

Final lab audits found all inner guests off, 112 installed package files byte-exact on each host, all 22/27 protected file hashes and all 19/16 unrelated VM baselines unchanged, and native fencing ready. Original data, non-owner fences and history remain. The tested TXZ SHA-256 is `b8903205d4ed7dc1730fcd2acb70226fb26b935b643e631dc9eba8edf2c16af2`; installer SHA-256 is `e6fbe08b8c691823e9a84f57f6ff4940a91264f16f8c891a1e5da738df26243b`, fixed installer ID `2026.11.12.01`.

The two lab hosts share one outer physical system; no outer VM/host reconfiguration was used to fake distinct hardware. A separately owner-authorized physical-host retry subsequently completed in the actual Cascade Lake to Skylake direction. This is evidence for that VM/profile/software combination, not certification of every heterogeneous pair or AMD nested migration.

## Physical-host result

The Windows Server 2025 guest had 8 vCPUs, 16 GiB configured RAM, a 90 GiB raw zvol, UEFI, software TPM 2.0 and an active Skylake-Server-v5 CPU profile. Both hosts used the exact qualified 0.5.1 candidate and matching Unraid 7.4.0 virtualization stacks. Its original kernel boot record, increasing uptime and TPM endorsement identity were preserved; the source became undefined/retired and the destination became the sole running owner. The guest did not report BitLocker information through the available API, so this run makes no claim about its protection state; active BitLocker remains separately proven by the lab test above.

| Measurement | Result |
| --- | --- |
| Initial disk copy | 90.076 GiB logical work in 17 min 38.5 s; **87.14 MiB/s** average |
| RAM/device migration phase | 36.124 GiB RAM payload, including recopies, in 6 min 41.0 s; **92.26 MiB/s** average; 142 passes |
| RAM dirtying | 44 MiB/s short preflight estimate; after-first-pass positive observations had a median of 101.9 MiB/s and a 1,022.5 MiB/s peak |
| Guest disk write activity | 0.083 MiB/s average; 5.07 MiB/s peak in approximately half-second samples |
| Native libvirt migration downtime | **1.328 s** |
| Full source STOP → destination RESUME | **8.007 s**, cross-host clock-corrected |
| Independent network reply gap | **8.034 s** |
| Whole job, including preparation and guarded activation | Approximately **24 min 50 s** |

Disk copying stayed online and continued mirroring changes during the RAM phase. The final QEMU mirror-completion counter reached 90.247 GiB, about 174.8 MiB beyond the initial ready counter; libvirt's final disk statistic retained the initial ready count. Disk rates above describe initial logical copy work, not NIC wire bytes. Frontend guest writes are a proxy for disk dirtying, not unique dirty extents. RAM rates are native processed payload, not configured RAM divided by elapsed time. Recopying explains why payload exceeded 16 GiB. The initial zero dirty-rate observations are not interpreted as absence of dirtying; interval-sensitive later estimates are reported separately.

**Operator intervention was required for convergence.** The observed QEMU default was a 300 ms downtime target. Bursty dirtying kept the remaining set above that budget despite repeated passes. With explicit owner approval, only this active job's target was raised to 3,000 ms through libvirt; handover then completed. No guest workload, CPU definition, transfer authority or fence was altered. This is not a new application default or UI feature, and a native downtime target does not cap the complete guarded pause. The substantial interval after native transfer completion and before destination resume remains a performance limitation; this test does not isolate its individual control/storage costs.

Original 9/14 unrelated VM XML/state/QEMU-lifetime baselines and 22/27 protected settings/key/OS hashes matched after the move. Disposable credentials were revoked/quiesced, private keys absent and exact job data-process counts zero. All temporary task files were exported and hash-verified before their narrowly scoped removal; official job history, native fences, retained source disk/TPM/NVRAM and upgrade backups remain. No unrelated production VM, host or VM Manager was restarted. Public feeds and release downloads remain **0.5.0**; 0.5.1 has not been published.

Measurement method: fixed exact-domain native job statistics, half-second read-only QMP/block samples, QEMU-supplied event timestamps and a separate approximately 20 ms network probe. Host clocks were bounded before/after through nonce/round-trip measurements; their observed relative offset was about 0.39 ms, with an approximately 0.71 ms sampling/drift bound. Initial disk-start inference has an approximately 17 ms observation bound. These are observed precision bounds, not a hardware timestamp guarantee. QEMU's [migration statistics](https://www.qemu.org/docs/master/interop/qemu-qmp-ref.html#object-migrationramstats) define dirty pages and page size; libvirt's [native event formatter](https://github.com/libvirt/libvirt/blob/v12.2.0/tools/virsh-domain.c#L9662) distinguishes QEMU timestamps from CLI receipt timestamps. The latter were retained as supplementary logs, not used for the final pause calculation.
