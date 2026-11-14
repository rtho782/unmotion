# Live handover delay qualification — historical engineering tests

Author: Richard Skinner.

Lab work on 12–13 November 2026. The measurements below used a private **0.5.2-beta1** engineering candidate, not a published release. Its delay reductions are included in **0.5.2-beta4**, whose integrated native-mode results and limits are recorded [separately](NATIVE-TRUSTED-MIGRATION.md). The older measurements do not describe the final native mode. Production hosts were not contacted or changed for the delay work. DEV01 stayed on Unraid **7.3.4**; DEV02 stayed on **7.4.0**.

## Changes under test

- Replace fixed completion polling in live-control commands with concurrent, event-driven input/output handling. The ordinary command runner and native bulk transport are unchanged.
- Run display statistics in a separate, lifetime-bound worker. A blocked or failed display query cannot synchronously stall the migration worker. The helper holds no migration/VM admission locks, cannot grant ownership, and is stopped outside successful handover.
- Flush newly written authority records once per freshly identified containing filesystem. Separate filesystems retain separate barriers; source retirement, commit intent and destination ownership still become durable before the corresponding acknowledgement/resume.
- Save monotonic, non-authoritative phase timings after destination resume. Timings and display progress are never execution authority.

Native receive-completion proof, fresh source-process absence, authenticated peer callbacks, receiver identity/storage checks, durable ownership ordering and native start fencing remain mandatory. No security profile or cipher selector has been implemented. Those are [accepted plans](ROADMAP.md#next-performance-beta--accepted-plans-not-released).

## Measured pauses

Passive QEMU STOP/RESUME events measure the whole guest pause, including guarded handover, not just QEMU's reported migration downtime. Before/after clock calibration bounds cross-host clock uncertainty below 1 ms in these samples. No extra diagnostic state/statistics polling was used during the measured transfers; the application's display sampling remained enabled except in the explicit sampler-failure test.

| Disposable guest / direction | Previous runtime | Test candidate | QEMU-reported candidate downtime |
| --- | ---: | ---: | ---: |
| File-backed Linux, DEV02 → DEV01 | 2.103 s | 1.419 s | 290 ms |
| Same guest, DEV01 → DEV02 | 2.166 s | 1.371 s | 232 ms |
| Two raw zvols, DEV02 → DEV01; display sampler deliberately stopped | Not an equivalent baseline | 1.697 s | 394 ms |
| Windows Server 2025 Core, two zvols, software TPM / active BitLocker, DEV01 → DEV02 | Not an equivalent baseline | 1.931 s | 388 ms |

The comparable file round trip averaged approximately **35% less pause**. These are a small number of nested-lab samples, not a promise of subsecond handover, physical-host performance or wire-speed transfer. The candidate does not change the bulk cipher or relax guarding.

One successful file move's destination phase timings were approximately 40 ms for locked status/receive proof, 52 ms for fresh receiver/storage evidence, 252 ms for the authenticated source callback, 27 ms for data-session revocation, 5 ms each for commit-intent and ownership durability, and 31 ms for resume. The source's post-native absence proof took 32 ms and retirement took 5 ms. The source's 675 ms paired commit measurement overlaps destination work and must not be added to it. These timings do not account for every part of native libvirt teardown or SSH startup, and do not establish the cause of the earlier production diagnostic query's wait.

## Qualification status

- Both complete Linux verification suites passed, including 22 command I/O/timeout/quoting checks, eight display-worker checks and 17 live-worker checks, plus existing native transport, fencing, CPU, storage and ordinary-workflow regressions.
- Normal guarded installation was followed by a fresh byte audit: all **114 runtime files** match on both labs; background services and native fencing were restored, with settings preserved.
- File round trip and zvol migration preserved the guest kernel boot, manual writer identity/token, advancing writes and file/raw-disk canaries. Stopping only the exact zvol display-worker tree did not interrupt migration; its descriptors contained no admission/VM locks.
- Windows Server 2025 Core retained its original kernel boot record, increasing uptime, manual-only writer identity/token, TPM endorsement identity, BitLocker protection/protectors and OS/data canaries. A subsequent full shutdown/start unlocked normally with BitLocker still enabled. The manual writer did not automatically restart after this cold boot, independently supporting its live-continuity evidence.
- Real cancellation during partial disk copy preserved the source boot/writer/canaries and sole ownership; the destination was aborted with no QEMU process. A fresh receiver inspection accepted the real guest CPU and rejected an injected unsupported `avx512fp16` requirement without changing its definition.
- Final audits passed on both labs: all 114 runtime files remain byte-exact; 19/16 unrelated VM baselines and 22/27 protected-file hashes are unchanged; native fencing and both daemons are healthy; every guest is off. All 11 distinct job keys, including old-runtime baseline/repeat tests, are revoked/quiesced with no private job keys or workers left. All ten passive-observer groups on each host exited. Backups, retained copies, ownership fences and history remain available for review.

These tests qualify the bounded performance candidate, not a general release or the future optional guards/cipher controls. They do not freshly repeat the complete host-loss, power-loss, reboot, cleanup and every guest/firmware fault matrix. The labs share an outer physical host; no new physical-host result is claimed.

Two earlier test-package attempts were skipped by normal package version ordering. Their VM results are correctly classified as old-runtime repeats, not candidate performance evidence. The final candidate was not tested until installed version and every runtime byte had been verified. No installer bypass was introduced.

Exact private candidate: installer ID `2026.11.12.06`; TXZ SHA-256 `623b6f5d81ff189c2332f7a339ce37a0bdb03808ae4e3abe1f5e8f2d86d1676b`; PLG SHA-256 `78558c863f01d55956485682d36820eb86d42ff1988fd0e921a480782d81a1d9`. Private raw logs, VM details and backups are not publication artifacts. Further fault/power-loss and release qualification remain separate from these performance tests.

## Subsequent fully native baseline — 13 November

A separate disposable, **unmanaged** Linux VM was created from an offline copy of the same file-backed fixture, with a new UUID/MAC, independent disks and a cold backup. It used the same two-vCPU, 1 GiB RAM, common CPU mask, BIOS, qcow2/raw-disk layout and manual writer/canaries. Original managed VMs, start hooks and ownership records were not modified.

This diagnostic bypassed **all three** extra layers: no Go data forwarders, no unMotion libvirt RPC filter, and no `--suspend` / separate application-controlled resume. OpenSSH forwarded the raw remote libvirt system socket and native RAM/NBD Unix sockets; ordinary `virsh migrate` handled finish/resume/confirm and source undefinition. Both endpoints' process snapshots during copying confirmed no unMotion live client, worker or native forwarder. Temporary full-access SSH keys were source-IP/expiry limited and strictly host-pinned; negotiated encryption remained ChaCha20-Poly1305 with compression disabled, including rekeys.

| Direction | Guarded candidate STOP → RESUME | Fully native STOP → RESUME | Native migration elapsed: guarded / baseline |
| --- | ---: | ---: | ---: |
| DEV02 → DEV01 | 1.419 s | **0.138 s** | 41.337 / **23.466 s** |
| DEV01 → DEV02 | 1.371 s | **0.168 s** | 39.707 / **24.397 s** |

Clock bounds were below 0.8 ms. QEMU's native downtime statistics were 154/182 ms; those use different boundaries from the passive STOP/RESUME events. The elapsed column is libvirt's native migration-job time, not the entire plugin workflow or a disk-only transfer duration. Disk work was approximately 3.70 GiB and RAM payload approximately 0.61 GiB per move in both comparisons. These are same-spec cloned-guest comparisons, not identical in-memory guest states or a statistically controlled physical-host benchmark.

The native path was about 1.7 times faster over the migration phase and removed most of the added pause in these samples. It changed multiple layers together, so it does not attribute the improvement individually to the forwarder, RPC filtering or ownership transaction. The destination RESUME events preceded the source SHUTDOWN signals by approximately 25/23 ms, illustrating native handover without waiting for source-process teardown first.

Both directions preserved the original guest boot, writer PID/token, advancing writes and canaries. The guest ended off on DEV02; source definitions were normally removed by libvirt. Both temporary key authorizations and key files were removed, original authorized-key hashes restored, tunnels/listeners closed, and all original 20/18 VM baselines plus 170/173 protected hashes—including application code, hooks and ownership records—were unchanged. Source fixture disks and independent cold backups were also verified unchanged.

This is **not** a product fast mode or approval to bypass existing managed-VM fences. The native baseline has no TPM and does not newly qualify Windows, reboot/failure recovery or the custom ownership system. At this stage both labs still shared one outer physical host. DEV01 stayed on 7.3.4; no production host, outer VM or feed was changed for this first experiment. The separately authorized physical-host test below followed it.

## Subsequent physical-host test — outer lab hypervisor

The owner then explicitly authorized a **live** move of the second lab hypervisor between the two physical hosts, using native SSH/libvirt rather than changing production unMotion. The first lab remained on its original host and Unraid **7.3.4**. The moved lab retained Unraid **7.4.0**, its IP/MAC and original OS boot ID; it was never shut down or rebooted. Fresh destination checks accepted its active common CPU mask, Intel VMX, firmware and software-TPM implementations. No unrelated production VM was changed.

The moved guest had eight vCPUs, 16 GiB configured RAM, UEFI, a software TPM and independent 700/16 GiB raw file disks. All inner guests were off. Native libvirt performed a full disk copy with zero detection, followed by RAM and native automatic handover. Its existing writeback caching was unchanged; libvirt's own safety checks were retained, with **no `--unsafe`**, no Go forwarder, no unMotion RPC filter and no extra `--suspend`. OpenSSH used AES-128-GCM, strict host pins and no compression.

| Measurement | Result |
| --- | ---: |
| Native command elapsed | **606.60 s** (10 min 7 s) |
| RAM phase, native active → completed | **33.63 s** |
| RAM processed / average | **15.42 GiB / 469.65 MiB/s** |
| Whole SSH connection, sent / average | **270.49 GiB / 456.09 MiB/s** (3.83 Gbit/s) |
| Clock-corrected QEMU STOP → RESUME | **0.288 s**, clock bound below 0.5 ms |
| QEMU-reported downtime | **290 ms** |

Storage pre-copy dominated roughly the first 9 min 32 s. The disk mirrors became ready just before RAM migration began. Libvirt reported **716 GiB of logical disk work**, including zero extents; this is **not** 716 GiB sent over the network. The SSH total includes disk, RAM, control traffic and protocol overhead, so it is not a separately measured disk-only throughput. Four RAM iterations completed; the final dirty-page statistic was zero, which does not establish a sustained dirtying rate. The idle lab hypervisor is not comparable to the earlier busy Windows production VM.

Passive events place destination resume about 9 ms before the source shutdown signal. The independent ping sampler recorded one timeout and a 1.09 s gap between successful samples; its one-second timeout prevented probing during much of that interval, so that gap is not an exact network-outage measurement. No reboot/recovery or active nested-guest migration was tested.

### USB licensing and completion

Contrary to the earlier assumption, physically removing the licensing USB triggered Unraid's live flash-device check and automatic array shutdown inside the lab guest. The OS itself stayed running. Two idle lab unMotion daemons held an inherited descriptor to an earlier **private installer helper on the pool**, delaying its unmount. Only those exact, lifetime-verified daemons were stopped; this private installation-path finding is recorded for follow-up, not claimed fixed by the migration.

After native arrival, the exact USB stick was hot-attached and recognised as the same Starter licence. The array, VM Manager and both unMotion daemons were restored normally, without an OS restart. All inner guests remained off; the saved USB rule moved to the destination and the original autostart setting was restored there. The inactive source definition was removed only after destination/USB proof, keeping **all original disks, NVRAM/TPM state, backups and pre-move snapshots**.

Final audits preserved every other VM's definition/state/process lifetime (9 source and 14 destination VMs), 132/137 protected-file hashes, all other USB rules, application code, pairing keys and native fences. The migrated lab also passed its existing 18-VM / 173-protected-hash audit, including original source/cold-backup disks; both daemons were healthy with zero workers. Temporary key authorization was removed, the original authorized-key hash restored, and private key files/tunnels/listeners were gone. Hash-verified metadata was exported locally before exact helper/log-folder cleanup. Shared system logs and VM recovery data were preserved.

This single physical-host success is a diagnostic, **not an implemented optional product mode**, a writeback-support change or a failure/reboot qualification. Production application versions and plugin feeds were unchanged. The lab's physical relocation now permits later cross-physical-host inner-VM tests, subject to their own scope and backups.
