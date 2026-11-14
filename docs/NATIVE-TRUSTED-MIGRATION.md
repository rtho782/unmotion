# Native migration trust and SSH encryption

Author: Richard Skinner.

Design and bounded qualification for **0.5.2-beta4**, published on the opt-in beta feed on 2026-11-14; stable/Community Apps remains **0.5.0**. Production was not accessed or changed for this work. Measurements and broader guest/fault tests below used the earlier C6 core build; the final C7 package adds an early legacy-queue launch guard and version metadata, with its own verification/upgrade/native smoke checks and [real host-loss/reboot qualification](NATIVE-HOST-LOSS-052-QUALIFICATION.md).

## SSH encryption

Settings offers AES-128-GCM (default) and AES-256-GCM for outgoing paired-host connections, including Cold/Warm Move, replication and live migration. Both encrypt and authenticate traffic. The longer AES-256 key can cost throughput on some hardware, but is not consistently slower in every environment. A connection uses exactly the selected cipher and fails if the peer cannot negotiate it; there is no silent fallback. Existing connections retain their cipher. Live payload sessions pin their selection for the job; ordinary workers capture it when they start. New independent control connections use the current host setting.

The cipher can improve any encryption-limited workflow. Native live handover chiefly reduces live guest pause, not Cold/Warm Move downtime. ZFS/rsync work, guest shutdown, disk speed and changed-data volume still determine other migration times. The existing validated ZFS receive header and PHP stream-copy path have not been changed by this candidate.

## Native live migration

Both paired hosts must explicitly enable **Native live migration trust**, and use the matching application and native capability. This grants a disposable migration key access to native libvirt/Unix-socket forwarding. Access to the system libvirt socket is effectively host-administrator access, not permission restricted to a single VM disk. Enable it only between hosts under trusted administration. It is not a security boundary against a malicious privileged peer, and key removal cannot undo actions already performed by such a peer.

Permanent pairing keys remain forced-command/restricted. The temporary native key is source-IP-bound and expires; normal registered sessions are closed and the key removed after use. PTYs, agent forwarding and X11 remain disabled. Native local forwarding is privileged, including TCP: OpenSSH cannot express a per-key Unix-path allowlist without also blocking the required Unix forwarding. unMotion's client opens only its migration sockets; ordinary remote TCP listeners are rejected by a restriction to an intentionally unusable reserved `.invalid` name. This is a trusted-administrator grant, not a sandbox. Expiry/key removal prevents new logins, but cannot undo actions or persistence established by an already compromised privileged peer. Payloads use native OpenSSH and QEMU/NBD, without the custom Go forwarder or libvirt RPC inspector.

Fresh CPU, machine, device, TPM, disk identity, conflict and capacity checks remain. Both hosts persist unresolved migration/start-blocking intent before native handover. Libvirt performs normal guest stop/finish/resume; recording final ownership and cleaning temporary access follow native resume rather than adding a second pause. The source durably records successful native completion bound to both admitted host boots and the mapped VM/storage contract. Authenticated reconciliation can also use current-boot receiver completion if the worker died before recording success. A guest that subsequently shuts down or is paused is not automatically restarted/resumed by bookkeeping. An ambiguous interruption requires reconciliation. A running, paused or absent destination alone is not proof of completed migration, and neither retained source data nor an old definition authorises another start.

The source copy remains retained. Old guarded jobs and their fences/receipts remain readable for reconciliation; they are not silently adopted as native jobs. An old queued job cannot launch into a new native reservation: resolve it and create a fresh native job instead. Native job schema 2 requires its mode/cipher fields, while legacy job schema 1 remains distinct. Wire protocol 7 is unchanged; new native live operations require an additional capability and matching versions. Ordinary Cold/Warm Move compatibility is not widened into unrestricted shell access.

## Qualification

The exact C6 package was built once and installed normally on DEV01 **7.3.4** and DEV02 **7.4.0**, now running on different physical hosts. DEV01 was not upgraded. Both full `scripts/verify.sh` suites passed with PHP/Bash/Node/Go and privileged transport/fencing tests, with no skipped-tool warnings. Real isolated OpenSSH tests exercised both ciphers, transparent Unix forwarding, denied remote TCP listeners, wrong-source/expired/removed-key rejection. Native contract mutations and missing/old-boot completion evidence were rejected. The final C7 guard build separately passed both full suites (including 72 native-mode checks), normal C6-to-C7 upgrades, exact final runtime checks and a new two-way native file-disk smoke test with unchanged boot/writer/canaries and complete key cleanup. Broader C6 guest/fault evidence is inherited, not claimed as a fresh C7 Windows/fault rerun.

The integrated candidate completed ten native transfers, including a lost-worker completion reconciled after guest shutdown; two additional partial-copy attempts tested cancellation and worker loss. The measured ordinary round trips were:

| Guest | Cipher, outward / return | Native migration time | QEMU STOP → destination RESUME |
| --- | --- | --- | --- |
| Linux file disks | AES-128 / AES-128 | 26.08 / 20.05 s | 193 / 159 ms |
| Linux ZFS volumes | AES-256 / AES-256 | 30.89 / 31.62 s | 195 / 230 ms |
| Windows Server 2025 Core, software TPM, active BitLocker | AES-128 / AES-256 | 122.41 / 119.59 s | 329 / 311 ms |

These are passive native migration/lifecycle events corrected with before/after clock samples; estimated clock uncertainty was below 0.75 ms. Native time excludes preparation and final bookkeeping. STOP/RESUME is a hypervisor pause measurement, **not** end-to-end service/network downtime. Directions, CPU states and workloads differ, so the cipher rows are not a controlled AES-128-versus-256 performance comparison. Native logical disk counters include zero handling and do not establish wire throughput. No subsecond guarantee follows from these samples.

Linux boots, manually started writer processes/tokens, advancing writes and disk canaries survived file/zvol round trips. Windows retained its kernel boot event, uptime continuity, manual-only writer, TPM endorsement identity, BitLocker protectors and OS/data canaries in both directions, then passed a protected full stop/start without a recovery prompt. One read-only heartbeat probe hit a transient access error and passed on retry; graceful shutdown completed normally after the private helper's short request timeout. No guest permission or protection change was used to obtain a pass.

Additional actual checks passed:

- Native process snapshots showed OpenSSH/virsh and the lease gate, with no Go payload forwarder or RPC-filter client.
- An exact source worker was stopped after durable native success; the receiver was gracefully shut down, and both hosts rejected duplicate starts before QEMU creation. After worker loss, authenticated reconciliation granted the stopped receiver ownership **without starting it**. Its subsequent explicit owner start and live return passed.
- Partial-copy cancellation and worker loss retained the original source boot/writer/canaries and restored its sole ownership after authenticated destination abort. A fresh round trip succeeded afterward; two original unrelated SSH connections survived job-key/session revocation.
- Actual ordinary workers passed a ZFS Cold Move using AES-256, sparse/shared-file Warm Move preparation/update/cutover using AES-128, and full AES-128 plus incremental AES-256 replication. Growing qcow2 extents and all test patterns arrived correctly. Only the exact disposable replica was retired/deleted; tiny test definitions were removed while source/test storage and history remained retained.
- The DEV01 browser rendered both cipher choices, the persisted selection and the explicit administrator-trust explanation. Unraid CSRF rules were not changed.

Final C7 audits found all inner guests off, both arrays/fences ready, two normal daemons and no transfer workers, native SSH clients, lease gates, old custom relays or display samplers. All **118 installed runtime files** matched the final candidate on both hosts. All 15 native-job credentials seen during development (including one earlier pre-transfer failed candidate) were revoked/quiesced on both labs and source private keys were absent. Original unrelated VM definitions, protected OS files, keys, pins and other settings were unchanged. Only the two requested new settings and expected peer version/capability/contact-time observations changed; pairing identities and credentials matched their hash-verified original backups. Both labs retain AES-128 and explicit native trust for future testing. Backups, retained source/partial data and fencing/history were preserved.

### Exact artifacts and remaining limits

Final C7 application `0.5.2-beta4`, fixed installer ID `2026.11.13.03`:

- TXZ SHA-256: `02f28276b0411a1d7f6a1a65dafd615aee628777cc793673247ea9b46ecc3ed0`
- PLG SHA-256: `96c9029662f98009e16135a4d39e253b62dd654462dea86962509e56104b51d8`

Preserved C6 measurement/fault-test build: application `0.5.2-beta3`, installer ID `2026.11.13.02`:

- TXZ SHA-256: `22b220d2f2bf5d9bfc9894f77e65edf8c90ac515ab7abf9387897d3ee9427ba6`
- PLG SHA-256: `f6a2b5c806eb67aa82e4cc29d02a07ef462df83d081e39f1e28b7bdc17de03b5`

The subsequent [2026-11-14 qualification](NATIVE-HOST-LOSS-052-QUALIFICATION.md) exercised clean cached reboots of both hosts, actual receiver loss during incomplete copying, actual source loss after native resume/durable success, authenticated reconciliation, fresh file/zvol round trips and a Windows protected boot. It did not physically inject every failure combination, disconnect the NIC for boot, repeat a public-0.5.0 upgrade, or test a real mixed-plugin-version pair. Earlier guarded-mode results are not substituted for native-mode fault evidence. A rebooted uncommitted receiver or both stopped copies without durable success evidence can remain fenced for operator review; absence alone never chooses an owner. There is no HA/automatic-failover promise.

TPM evidence is the stated Server 2025 Core/TPM-only BitLocker fixture, not Windows 11, TPM+PIN or every PCR/Secure Boot profile. Existing CPU, device, storage, encryption and writeback-cache eligibility limits remain. Cold/Warm Move and replication were functionally tested, not benchmarked for throughput improvements; their existing PHP ZFS receive-header/stream-copy path is unchanged. The beta feed offers this candidate; stable remains unchanged and production installation is left to its administrator.
