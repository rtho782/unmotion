# unMotion 0.5.2 stable

Author: Richard Skinner. Released 2026-11-14. Installer ID **2026.11.14.03**; Plugins displays **2026.11.14.03-0.5.2**.

## Release scope

This promotes **0.5.2-beta6** to stable, changing release/version metadata rather than application behaviour. The PHP version constant, VERSION file and native connector's reported version become 0.5.2. Native source, transport, fencing, ownership/cleanup contracts, installer commands and compatibility transitions are unchanged. Package modes and unrelated file bytes are preserved. Both feeds offer the same stable package; descriptors differ only in their channel URL so beta installations retain future opt-in.

No additional stable-build host/upgrade campaign was run: the owner explicitly accepted promotion on existing qualification. Packaging checks are not described as fresh guest, host-loss or upgrade tests. Installed hosts are left for their administrators to update through Plugins.

The release includes native OpenSSH/libvirt live handover, AES-128-GCM/default and selectable AES-256-GCM, freshly validated same-vendor CPU masks, normal VM completion and automatic exact obsolete-source cleanup. Live moves require **matching 0.5.2 and explicit administrator-equivalent native trust on both hosts**. Protocol 7 and unrelated ordinary capability gates are unchanged. See [instructions](LIVE-MIGRATION-BETA.md), [trust model](NATIVE-TRUSTED-MIGRATION.md) and [update precautions](PLUGIN-UPDATES.md).

## Qualification carried forward

- [Beta6](NORMAL-LIVE-COMPLETION-BETA6.md): both full lab suites on DEV01 Unraid 7.3.4/DEV02 7.4.0; running-owner updates, file/zvol/Windows TPM live moves, protected boot, cancellation/reviewed cleanup, normal CPU/clone admission, autostart, Cold/Warm returns and coordinated recovery/retirement. Final-candidate and inherited Windows checks are distinguished in that record.
- [Native transport/trust](NATIVE-TRUSTED-MIGRATION.md): file/zvol and Windows Server 2025 Core/active TPM-only BitLocker qualification, both ciphers, ordinary Cold/Warm/replication cipher selection, interruption/reconciliation, key/session cleanup and duplicate-start fencing.
- [Host loss/reboots](NATIVE-HOST-LOSS-052-QUALIFICATION.md): actual lab receiver restart during copying, source restart after native resume/durable success, authenticated reconciliation and cached reboots. These beta4 transport tests are inherited, not newly rerun for beta6/stable; they are not physical power-cut/controller-durability certification.

## Later physical-host performance check

Published beta6 completed an operational roundtrip of an existing Windows VM between two Intel physical hosts, both on Unraid 7.4.0/libvirt 12.2.0/QEMU 10.2.3. It used an active common CPU mask, 8 vCPUs, 16 GiB RAM, a 90 GiB raw zvol and software TPM, native SSH/libvirt and AES-128-GCM. No guest reboot, workload change, guard bypass, higher pause target or other production VM operation was used.

| Measurement | Earlier guarded development run, same outward direction | Beta6 outward | Beta6 return |
| --- | --- | --- | --- |
| Disk initial copy | 1,058.5 s / 87.1 MiB/s | 126.3 s / 729.8 MiB/s | 178.6 s / 516.1 MiB/s |
| RAM transfer | 401.0 s / 92.3 MiB/s | 35.2 s / 477.4 MiB/s | 35.8 s / 462.0 MiB/s |
| RAM processed / passes | 36.12 GiB / 142 | 16.42 GiB / 5 | 16.14 GiB / 6 |
| Corrected QEMU STOP→RESUME | 8.007 s | 0.246 s | 0.211 s |
| Network reply gap | 8.034 s | 0.296 s | 0.293 s |
| Migration job, excluding later cleanup | 1,490 s | 193 s | 244 s |

Native QEMU downtime was 250/211 ms; cross-host clock bounds were below 0.7 ms. Late positive RAM dirty-rate samples were approximately 20/11 MiB/s, with initial probes 28/52 MiB/s; early zero reports do not prove no dirtying. Guest frontend writes during sampled disk copying averaged approximately 0.08 MiB/s and peaked near 0.9 MiB/s, not unique dirty extents.

The old successful baseline was a private 0.5.1 development run after a blocked 0.5.0 preflight, not a released 0.5.1 or successful 0.5.0 transfer. Workload/cache were not held constant: this is a combined operational improvement, not an isolated cipher/transport A/B. Disk counts are logical migration work, including sparse/zero ranges, not wire bandwidth. RAM includes retransmitted pages. No particular speed or subsecond pause is guaranteed elsewhere.

Original Windows boot identity and advancing uptime survived both legs. The VM finished as a normal owner on its original host; both exact obsolete source copies were cleaned automatically. Disposable access was revoked/quiesced, unrelated VM definitions/QEMU lifetimes and protected configuration were unchanged, and task-only helpers were removed. Private VM-identifying evidence, credentials and raw archives are not published.

## Remaining limits

No automatic failover/unreachable-source takeover, AMD nested live migration, passthrough migration, shared writable disks, attached ISO, native ZFS-encrypted zvol or arbitrary TPM backend support is introduced. Software TPM remains experimental within its tested scope; Windows 11/TPM+PIN/every PCR/Secure Boot profile and every hardware/failure window are not qualified. Live controls retain their interface beta labels. Unknown fencing changes block managed-owner updates; active recovery-managed guests require shutdown. Never delete records to bypass a refusal.

See [normal completion/cleanup](NORMAL-LIVE-COMPLETION-BETA6.md), [replication](REPLICATION.md) and [coordinated recovery](RECOVERY.md) for separate authority/retention rules. Keep independent verified backups.

## Artifact checksums

- `unmotion-0.5.2_stable-noarch-1.txz`: SHA-256 `0c551e863df8aab501f144776bf03f325faf84c8cfbc1bfdc05f0b177ae79621`.
- `unmotion-0.5.2.plg` / stable feed: SHA-256 `4addb1cc71605ac59fe0ea781f0a51233d637433f8b0f7ddb1ffab2ce27237b7`.
- `unmotion-0.5.2-beta-channel.plg` / beta feed: SHA-256 `f571d8b4232da0e9c4458ae0e6fee4978203a47474b2159c3bdae13a2dd9110c`.
