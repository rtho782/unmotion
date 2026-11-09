# Beta3 live-migration qualification

Author: Richard Skinner

Test dates: 8–9 November 2026. Qualification used private 0.5.0-beta3 builds before release. The reviewed final package is published unchanged as the [Beta3 prerelease](https://github.com/rtho782/unmotion/releases/tag/0.5.0-beta3); stable remains 0.4.9. Production VMs and production plugin installations were not changed by these tests.

## Environment and build

DEV01 runs Unraid 7.3.3; DEV02 runs 7.3.2. Both expose libvirt 12.2.0 and QEMU 10.2.3 on nested Intel KVM. Disposable Ubuntu guests use BIOS, `pc-q35-9.2`, an active `Cascadelake-Server-v5` custom CPU, two vCPUs and 1–2 GiB RAM. Their names include **Squid Proxy**. Separate independent raw and qcow2 files were used; the raw guest has no NIC and a password-protected VNC console.

The continuity workload keeps a process alive, dirties guest RAM and appends/fsyncs a numbered disk log. The networked guest also streams those records over one persistent TCP connection. A full guest stop/start between test groups deliberately changes its boot ID; continuity comparisons are within each test, not across those restarts.

The tested/published package SHA-256 is `a5847188809f158c8e13c149d22e430cf9d5a13faf991123c1d7890cede3a7ef`. Installer ID: `2026.11.08.03-0.5.0-beta3`. Both test hosts have guarded upgrade backups and underlying lab-disk ZFS snapshots, including `unmotion-beta3-reset-baseline-20261109`. No broad recursive snapshot/send/delete was used.

At handoff, both fixtures are stopped on DEV01 with OWNER authority; DEV02 has no fixture definitions and retains RETIRED fences. Both arrays/VM Managers are online, native fences are ready and no live job remains unresolved. Original lab settings, pairing keys, CPU observations, saved original VM XMLs and Unraid's parent hook remained intact. Source/partial disks and upgrade backups are retained. DEV02's post-reboot installed payload was compared with its cached package and matched.

## Observed results

| Check | Result |
| --- | --- |
| qcow2 live migration in both directions | Passed on earlier iterations of this candidate. Same guest boot/process, contiguous durable log and surviving TCP connection. Maximum observed packet gaps were 1.85 and 2.13 seconds, not a guaranteed 500 ms stun. |
| Raw live migration both directions | Passed. DEV01 → DEV02 on the earlier candidate and DEV02 → DEV01 on the final package. Boot/process unchanged, contiguous disk log and VNC password preserved. |
| Cancellation during copying | Passed. Native libvirt abort left the source running/owner, removed the incoming definition and retained partial disk data. Same guest boot/process; observed TCP gap about 212 ms. |
| Abrupt disk-relay loss | Passed. Migration failed clearly; original guest continued on its original boot/process. Paused receiver removed, partial file retained. |
| Worker killed during copying | Passed after fixing orphan cleanup. Fresh reconciliation withdrew the abandoned transport lease and returned ownership to the running source. |
| Worker lost; native copy completed to a paused receiver; runtime token lost | Passed. Neither VM ran concurrently. Explicit authenticated reconciliation renewed credentials, proved source absence and resumed the exact incoming guest with its boot/process/log intact. The extended pause was a recovery test, not a successful low-stun measurement. |
| DEV02 unclean reset during copying | Passed on the final package. Cached package restored; unresolved source remained stopped. A direct native start was rejected by the hook before QEMU. Reconciliation aborted the incomplete destination and left the source stopped until an explicit owner start. |
| Restarted lab services/licensing | DEV02 retained its licensing attachment. The lab array was manually started after boot; this is not evidence of unattended array autostart. |
| Destination UUID conflict / retired source start | Passed. Conflict rejected; manually restored retired source XML could not start before QEMU. |
| Pending CPU change / shared user-path alias | Passed on the final package. An alternate next-boot CPU was rejected while the original CPU remained active. A stopped disposable definition referencing the same disk through a user-share alias was rejected. The temporary definition was removed without deleting disk data, and original persistent XML was restored byte-for-byte. |
| Unmanaged VM start | Passed on DEV01; disposable unmanaged Squid Proxy started and was stopped again. Original recovery-managed guests remained stopped/fenced. |
| Browser workflow | Immediate styled preflight with disabled submit, real start/cancel, progress/log refresh, active-worker Reconcile disabled, legacy actions blocked on live-managed VMs. The build hash avoids stale JavaScript between private builds with the same version. |
| Upgrade preservation | Both final-package installations preserved settings, pair keys, CPU observations, saved VM XML and the existing Unraid parent hook. |
| Handover-boundary worker failure | Passed on the final package. The worker was killed at SOURCE_RETIRED; the already-issued authenticated commit completed. Explicit reconciliation confirmed destination ownership without rebooting/resuming another copy. Boot/process/log and the original TCP connection survived; observed maximum gap about 1.92 seconds. |

## Automated verification

The full `scripts/verify.sh` suite passed in an isolated Linux mount namespace using PHP, Bash and Node, including existing migration, clone, replication, native fencing, process identity, pairing, storage/ISO/firmware, concurrency, packaging and UI regressions. The final run includes 35 live RPC checks, 23 live native-fence checks, 45 live preflight checks, 17 authority-record checks and 9 worker/commit-order checks, plus live UI and 44 POST-method/CSRF-route checks. Actual browser mutations used the normal Unraid CSRF path; route tests do not themselves claim to validate Unraid's token implementation. Live cases span private iterations; the final package repeated full automated verification, power-loss recovery, the retirement-boundary case, raw migration, and CPU/alias rejection checks after the final bookkeeping-only fix.

Windows PHP/Node syntax and portable checks also ran. Linux-only filesystem/process checks were run on Linux, not counted as passes from an unavailable Windows environment. A staging-only text-normalization mistake corrupted binary test fixtures in one export; that export was discarded/re-extracted before successful verification and was never installed.

## Issues found and addressed

- Destination migration flags and libvirt heartbeat framing needed exact ABI validation; accepted values are now narrow and regression-tested.
- Ordinary XML dumps redact VNC credentials. Private secure dumps and target transformation preserve console authentication without exposing them in the preview/job-list API.
- Transport children could inherit VM/admission locks after a worker crash. Live locks now close on exec; explicit reconciliation revokes the orphaned transport lease before inspection. Process termination still requires exact lifetime/command ownership.
- Destination commit intent must be persisted and synced before granting ownership, so interruption cannot leave an unrecognized OWNER/ready-state combination.
- Shared-disk checks cover both active and saved definitions, physical files, hard links and user-share aliases.

## Release limitations and remaining qualification

This is an experimental, narrow path, not general live-migration support. See [requirements and workflow](LIVE-MIGRATION-BETA.md). It supports one independent file disk and BIOS; UEFI, TPM, passthrough, ISOs, zvols, multiple disks, recovery/replication management and several host-specific devices are excluded. Only one live job may run per host.

Completed guests remain live-managed: native owner starts and further live moves work, but this candidate blocks their legacy cold/warm, clone, replication and bulk-CPU workflows. A safe hand-back/unmanage lifecycle is not implemented. Old and partial disks are retained, not automatically reclaimed. These are explicit product-review decisions, not silently claimed as complete features.

Windows guests, i440fx end-to-end migration, differing physical CPU generations, physical 10/25 GbE links and destination-host power loss have not been qualified. The restricted control adapter depends on the reviewed libvirt 12.2.0 wire ABI; new versions require separate qualification. No automatic failover, postcopy, unsafe mode, forced takeover or offline fallback is included. Compatibility rejection tests do not prove every heterogeneous host combination.

An open browser tab lost authentication when DEV02 rebooted and continued polling login redirects until navigation. That existing polling/session-expiry behavior is a follow-up UI issue; the test did not weaken authentication or CSRF to work around it. Destination rows currently show their reservation state until commit; detailed transfer progress is on the source. Destination-side progress wording is another review/polish item.
