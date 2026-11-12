# 0.5.0 throughput qualification — earlier PHP batching candidate

Author: Richard Skinner

This records the **earlier unpublished PHP batching candidate (C2)**, not the released transport architecture. Stable 0.5.0 replaces that payload loop with a native connector and disposable SSH keys. See [native transport qualification](NATIVE-TRANSPORT-QUALIFICATION.md); C2 measurements and teardown observations below are historical evidence, not claims about the released package.

Scope: DEV01 on Unraid **7.3.4**, DEV02 on **7.4.0**, using disposable lab guests. No production access, production VM changes, OS upgrade or publication forms part of this task. Initial physical-host qualification and its complete production teardown are recorded separately in [Beta9/final qualification](BETA9-QUALIFICATION.md).

## Finding and correction

The PHP relay requested 256 KiB from `fread`, but its underlying streams retained PHP's default **8 KiB** chunk size. Each small read incurred a fresh job/native-authority validation, including JSON parsing and filesystem checks. This was substantial avoidable CPU overhead, not a configured 32 MiB/s libvirt bandwidth cap.

Set the underlying stream chunk to **256 KiB** for source relay sockets/SSH pipes, destination data pumps and the destination bandwidth-sample reader. Actual reads can still be smaller: the isolated Linux pipe test returned 64 KiB chunks. Buffers remain bounded and nonblocking, and every event-loop iteration still rereads execution authority. There is no lease cache, reduced checking interval, different cipher, unrestricted connection, protocol change or increased timeout. Small NBD channels may remain idle within the existing total transfer window.

## Measurements

These are nested-lab results, not physical 10/25 GbE certification or universal throughput promises.

| Check | Initial candidate | Corrected candidate |
| --- | --- | --- |
| 256 MiB isolated pipe read with real record-validation work | 32,769 checks; 6.740 s elapsed, 6.445 s inside validation | 4,097 checks; 1.106 s elapsed, 0.855 s inside validation |
| DEV01 → DEV02, fully patterned 1 GiB raw file + 1 GiB zvol | 34.757 s last copy sample with 18 MiB remaining; roughly 37 s to handover | Complete 2 GiB copy observed at 14.409 s; roughly 16 s to handover |
| Same-direction encrypted admission sample | 32.40 MiB/s | 73.81 MiB/s |
| DEV02 → DEV01, same two disks | Not measured | Complete copy observed at 11.201 s; admission sample 74.64 MiB/s |

The mixed-disk test verified **every byte of both disks** after each move. Its guest is firmware-only: this establishes transport/storage integrity, not application continuity. Running-OS checks are recorded below.

Single-stream `iperf3` carried 2.128 Gbit/s forward and 2.024 Gbit/s reverse. Verification of the **original baseline source** read both disks at about 1.48 GiB/s; post-transfer copies also verified above 1 GiB/s. Neither raw link capacity nor source read speed explains the initial much lower relay throughput. The encrypted admission sample includes connection/startup overhead and is intentionally not treated as sustained copy speed. The corrected relay still has encryption, validation and scheduling overhead; it does not promise wire speed.

## Running-OS and rollback checks

| Guest/workflow | Result on the corrected candidate |
| --- | --- |
| Ubuntu file-backed, i440fx; 12 GiB qcow2 + 64 MiB raw | Cancelled after 256 MiB had copied. Source remained running sole owner; destination was aborted/QEMU absent. Original boot ID, unique writer token/PID and both canaries survived. A new live attempt then completed in 48 seconds, retaining that boot/writer and advancing its counter. Native statistics observed about 3.69 GiB of disk work, not a full 12 GiB wire transfer. |
| Ubuntu zvol-backed, Q35; 12 GiB + 64 MiB volumes | Live move completed in 1 minute 43 seconds. Original boot ID, unique writer token/PID and both canaries survived, with an advancing counter. Native statistics observed about 12.07 GiB of disk work. |
| Windows Server 2025 Core; Q35/UEFI/TPM 2.0 CRB, active TPM-only BitLocker; 40 GiB + 128 MiB zvols, 4 GiB configured RAM | Live move completed in **5 minutes 26 seconds**, including preflight/handover; last native copy sample was 308.083 seconds. About 40.15 GiB disk work and 1.55 GiB RAM bytes were observed. Disk copying sustained roughly **135–140 MiB/s**; last RAM sample was about 150 MiB/s. The unique writer token/PID continued, TPM endorsement identity and protector IDs matched, both canaries matched and BitLocker stayed fully encrypted, unlocked and on. A subsequent full stop/start booted protected without suspension or recovery-key entry. |

The earlier roughly 28-minute Windows run used different physical hosts and a different resident-RAM/dirty-page workload. It is historical context, **not a controlled before/after speed ratio**. The controlled same-direction lab comparison is the mixed raw/zvol row above.

The Windows test initially flagged a change in CIM's derived wall-clock `LastBootUpTime`. This was not a restart: monotonic kernel uptime remained consistent with the original launch, the original kernel boot event remained, and the same manually started unique writer process continued updating its heartbeat. Its scheduled task has no boot trigger. The protected cold-boot check subsequently observed a new kernel boot-event record and reset uptime. No guest clock/host timezone configuration was changed to make the test pass.

Both labs' inner guests are stopped at handoff. Current test owners are on DEV02; source and partial copies, authority/history and private pre-test backups remain intentionally retained for review. No production host was contacted in this correction task.

## Safety and candidate evidence

- Full `scripts/verify.sh 0.5.0` passed on **both DEV01 and DEV02**, including PHP/Bash/Node syntax and regressions, real restricted-transport integration and the new socket-based binary-integrity/half-close/backpressure/revocation tests. No verification warnings or skipped-tool messages were reported. Optional profilers `perf`/`strace` were unavailable; direct timing, native statistics and process CPU observations were used instead.
- The revocation test withdraws authority after reading bytes and verifies those queued bytes are not delivered, and that neither data nor human-readable errors leak into the output. Existing native fencing, RPC validation, process-lifetime, cancellation and idle/total-window regressions also passed.
- Both labs' inner guests were stopped before guarded installation. C1 package/descriptor/state were backed up privately. Final audits confirm all **20/25** protected OS boot files, settings, root keys, peer pins/records and existing native hook files match their pre-install hashes exactly; native fencing and licensed arrays remain ready. All **108** installed runtime files on each lab match the corrected package.
- The only runtime differences from C1 are the batching helper/call sites in `include/live-migration.php` and the benchmark reader in `unmotion-live-io`. Matching 0.5.0 peers remain required for live operations; no compatibility expansion is implied.

Corrected lab candidate package SHA-256: `bec69309ab4cd6d8a6437428f83137db8cdb7f58ff6f6e16c2b65d737c56962d`. Descriptor SHA-256: `d445b3e56a5c51db76b6d0f3315320a0409c511f23ba6ecc0f9a63bc12bce49c`. Full verification log SHA-256: `8b1ec8defd9fdaa99d363cd017188bd3e991144f8cd5a6f766ffafe0b376cd50`.

This corrected candidate was qualified for the bounded scope above but was **never published**. The later native-transport candidate was reviewed and published as stable 0.5.0. The existing 30-minute transfer limit remains; large/busy guests need measured throughput and convergence margin. Windows 11/TPM+PIN, AMD nested live migration, passthrough and new physical failure combinations are not qualified by these tests.
