# Native live transport — 0.5.0 qualification

Author: Richard Skinner

Historical scope: disposable guests on DEV01 **Unraid 7.3.4** and DEV02 **7.4.0** only; no production access/OS upgrade formed part of this transport replacement. The owner approved stable 0.5.0 publication on 2026-11-12; the artifact identities below belong to that release. Current stable/Community Apps and both feeds offer **0.5.2**, with the later [native trust model](NATIVE-TRUSTED-MIGRATION.md) and [normal completion](NORMAL-LIVE-COMPLETION-BETA6.md). This document is inherited qualification, not the current transport description. Publication never updates hosts automatically.

## Architecture and security

The packaged `unmotion-live-stream` executable replaces PHP's live disk/RAM byte loop. QEMU traffic still travels over native OpenSSH with the existing strict server-key pins. PHP admits the exact job endpoints, passes their descriptors, supervises children and persists optional display counters. The existing PHP libvirt control adapter continues to validate the reviewed ABI, exact XML/disks/sockets/flags and operation order. Cold/Warm Move, clone and scheduled-replication data paths are not replaced.

Each authorized live job generates its own temporary Ed25519 key in the source's private RAM directory. Its public key is registered through the existing authenticated paired interface. The receiver installs a restricted, expiring forced-command entry bound to that job/key. It admits only the approved memory/disk sockets or the bounded bandwidth sample, never a user-selected file, shell, libvirt-control socket or general forwarding destination. The permanent pairing credential remains the control credential, not a payload bypass.

OpenSSH's [`restrict`, forced-command, expiry and `permitopen` options](https://man.openbsd.org/sshd.8#AUTHORIZED_KEYS_FILE_FORMAT) provide the underlying restrictions. `permitopen` describes host/port allowlists, not an equivalent arbitrary Unix-path allowlist; this implementation therefore keeps forwarding disabled and uses a fixed validated connector. There is no global `sshd` reconfiguration, new listener, port, account or additional host-trust prompt.

Removing an authentication key is not treated as proof that an already-open connection has ended. Completion/abort marks its runtime authority revoked, removes the credential and separately quiesces native data processes and exact SSH sessions. Before signalling a saved SSH session, unMotion re-proves the current job-gate ancestry and exact boot/start/executable/argument identity. It never targets the main SSH daemon or an unrelated login. Source private/public key files are removed. Current-boot/expiry checks and local lifecycle housekeeping also fail closed after reboot, timeout or interrupted cleanup.

The native connector accepts **inherited descriptors only**, with a parent lifeline and separate telemetry pipe. It opens no supplied disk/socket destination, executes no shell and cannot grant VM ownership. It closes unrelated inherited handles, uses bounded 1 MiB queues per direction and 256 KiB I/O batches, and preserves half-close/backpressure behavior. Every loop freshly validates bounded, root-owned, no-symlink job/authority/managed-marker/key records plus immutable job/host/boot bindings and wall/boot-uptime expiry. Checking occurs after polling and before I/O; idle polling is at most 250 ms. No execution-authority cache is introduced. Errors go to stderr, never the binary payload stream.

Source retirement, the successful native-finish receipt, paused receiver admission and fresh source-absence proof still gate destination ownership/resume. Credential cleanup must complete before that ownership grant. Native start hooks remain local and command-free. A credential or progress counter never grants VM authority.

Wire protocol **7** and live ABI **4** are unchanged. Both live peers must additionally advertise fresh `liveDataTransportVersion: 1`, backed by the installed version-matched native executable. An actual new-candidate/older same-version lab pairing rejected live preflight before creating a job/key. There is no PHP payload fallback; otherwise-supported ordinary protocol-7 compatibility remains capability-gated as before.

## Tests completed

| Check | Evidence/result |
| --- | --- |
| Full regression suite | `scripts/verify.sh 0.5.0` passed on both Unraid versions, including PHP/Bash/Node checks, privileged restricted transport, native fencing, RPC/process/upgrade, CPU/UI, storage/progress and ordinary-workflow regressions. No missing-tool/skipped-test warning. |
| Native guard and binary transport | Fresh state/key/host/boot/token mutations, malformed JSON/UTF8/UTF16/case/null scalars, missing/corrupt/foreign-root/symlink records, immutable binding changes, expiry, bounded queues, full duplex, half-close, backpressure, revocation and supervisor-loss tests passed. Benchmark requires exactly 32 MiB within its bound. |
| Real PHP descriptor handoff | A compiled child transferred 8 MiB in / 2 MiB back with binary integrity and half-close, and did not inherit the parent's private lock. PHP did not read/write VM payload. |
| Actual job-key isolation | Arbitrary commands, foreign jobs/paths/channels, wrong tokens, control socket access, permanent-key payload bypass and TCP forwarding were rejected. An extra authorized NBD connection reached only its approved job socket. |
| Receiver session revocation | An authenticated revoke closed that deliberately held SSH connection while probe stdin was still open. Reusing its exact copied key then failed at SSH authentication. A separately held unrelated SSH process remained alive and exited normally. |
| Ubuntu i440fx file disks | After receiver revocation during copying, source remained running OWNER and destination ABORTED/undefined/QEMU absent. Retry completed in 46 s. Boot ID, unique writer token/PID and both disk canaries matched; counter advanced. |
| Ubuntu Q35 zvols, final candidate | During a partial copy, SIGKILL targeted only the freshly proven disk admission parent, not its children. Two native children disappeared within 10 ms. Source rolled back to running OWNER, receiver ABORTED/undefined. A fresh-key retry completed in 2 min 19 s. Original boot ID, writer token/PID and both canaries survived with an advancing counter. Active-upgrade admission refused while unfinished work existed. |
| Windows Server 2025 Core, final candidate | Q35/UEFI/TPM 2.0 CRB, active TPM-only BitLocker, 40 GiB + 128 MiB zvols and 4 GiB configured RAM completed DEV02 → DEV01 in **7 min 36 s** including preflight/commit. Same boot event, increasing monotonic uptime, original manual-only writer token/PID, TPM endorsement identity, protector IDs and both canaries matched. BitLocker stayed 100% encrypted, unlocked and on. A subsequent full stop/start booted protected without suspension or recovery-key entry, then shut down normally. Small data channels legitimately stayed idle beyond five minutes during the larger copy. |
| Completed-key replay | A privately retained exact Windows job key was refused at SSH authentication after successful completion; the original runtime private key was gone. The test copy was removed afterward. This is separate from the active-revoke/held-session test above. |
| Exact installed candidate | All **113 runtime files** match the final package on both labs. Native fencing/licensed arrays remain ready; all inner guests are stopped. All **20/25** original protected OS boot/config/SSH/pairing/hook files match, with no observation-only exceptions. All nine local credential records on each lab are revoked/quiesced; no disposable authorized key, source private/public key or transfer process remains. |

Final-candidate mixed-disk round trips used the same fully patterned 1 GiB raw + 1 GiB zvol fixture, with other guests stopped. Both entire disks were verified byte-for-byte after **each** move. Reverse completed in 28 s total and forward in 29 s, including preflight/commit. The last forward sample had 1.943 GiB copied at 11.502 s, with 58 MiB remaining; the job completed three wall-clock seconds later. Reverse had 1.884 GiB at 10.956 s with 119 MiB remaining, followed by completion three seconds later. These are partial-sample/timestamp observations, **not exact full-copy timings**. Earlier same-direction PHP batching observed a roughly 16 s forward disk phase, versus roughly 37 s originally; no universal speed ratio follows from the measurements. See the [earlier diagnosis and C2 results](THROUGHPUT-QUALIFICATION.md).

Windows disk work was about 40.15 GiB and the final observed RAM stream about 1.30 GiB, with disk throughput roughly **98 MiB/s** and last RAM sample about 97 MiB/s. That is slower than C2's roughly 135–140 MiB/s Windows observation in the opposite direction; it is not a controlled same-path performance comparison. Replacing PHP does not make validation, encryption, storage and nested-host contention free. The zvol fault/retry also overlapped full regression verification. No wire-speed claim is made.

Windows continuity uses the original kernel boot event plus monotonic uptime and the unique manual-only writer, not CIM's derived wall-clock boot field alone. The protected cold boot generated a new boot-event record and reset uptime; its initial RTC/event UTC was eight hours behind the later corrected guest clock, an existing lab clock-layout observation also seen in C2. No host/guest clock settings were changed to pass qualification.

Source/partial copies, start fences/history and private pre-test backups remain intentionally retained on the labs for review. Current file, zvol and Windows owners are stopped on DEV01; the small mixed fixtures are stopped on DEV02. No production host was contacted.

## Exact candidate evidence

| Artifact | SHA-256 |
| --- | --- |
| `unmotion-0.5.0_stable-noarch-1.txz` | `98797cc5881211dd41cff715cb4aba3ca44b0cc533906bc6f15243728ae39af0` |
| `unmotion-0.5.0.plg` | `3c8585aff641ec035c2f60c2f0e892d8e510bdf5537d8f697c57d1b7558ac142` |
| Installed runtime manifest (both labs) | `389a76738e86f28ba616b10e7c72340ef010fec3ce0f70046d1bbdc022a2d89f` |
| Full DEV01 verification log | `82fef99a9a62a4a81f85cc1db938f2e69e0b346b6c49b0f438cc0599bd2eeab1` |
| Full DEV02 verification log | `c858f1bd32f90f4bfc76d31c30405cd75dc245736c7a745141de929fdf6aa1a2` |

These released artifacts retain fixed installer ID **2026.11.11.02**, allocated during qualification. They were published without rebuilding. Prior candidates remain private and were not substituted into any earlier public release. Full candidate verification preceded installation; subsequent release-guide changes are documentation only.

## Build and remaining limits

The static Linux/amd64 helper targets the x86-64 v1 instruction baseline, has no external Go modules or C runtime dependency, and embeds the application version. The official Go toolchain was installed only inside private lab build directories; end users do not install Go. Source, build/test instructions, pinned qualification toolchain digest and upstream BSD licence provenance are in [the native source guide](../native/live-stream/README.md). The package includes the Go licence alongside unMotion's GPL-3.0-only licence.

The existing 30-minute worker limit and transport cleanup grace remain. Encryption, per-iteration validation, QEMU/storage and host contention still cost time; replacing PHP does not promise wire speed, subsecond handover or adequate bandwidth for every VM. Optional profilers were unavailable; direct timing, native job statistics and process observations were used. No new physical-host power-loss, AMD nested live migration, passthrough, Windows 11, TPM+PIN or arbitrary virtualization-stack qualification is implied. Earlier physical/host-loss results are inherited fencing/control evidence, not a fresh test of this transport on production.
