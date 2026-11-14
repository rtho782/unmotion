# Upgrading with a migrated VM running

Author: Richard Skinner. Qualification recorded 2026-11-14 for **0.5.2-beta5**.

A completed one-off live migration is not ongoing replication. unMotion retains a local **OWNER** record at the destination and a **RETIRED** fence for the old copy. Keeping that protection does not mean the settled owner must be shut down for every plugin update.

## Upgrade behaviour

Beta5 permits an already-running, settled owner to keep its existing native admission during a compatible update. The installer verifies:

- The target package pins the complete eight-file native start-fence dependency set, and the installed files are identical. This is an exact compatibility check, not a guessed version range.
- The existing fence proof belongs to the current libvirt process and host boot.
- The owner's existing RUNNING admission matches its original QEMU PID, start time, executable, boot and UUID. No new process is adopted or admission invented.
- Libvirt and the process table agree, and no unfinished live migration, pending hand-back, conflicting recovery authority or active transfer worker remains.

Checks run before stopping the plugin daemons and again before replacing installed files. The VM, libvirt and host are not restarted. Ownership, native admissions, old-copy fences and job history are not rewritten to make the update pass. The checks are local; they do not obtain a new ownership grant from the peer.

An update that changes the fencing code, or encounters missing/inconsistent admission evidence, still refuses the running-owner exception. Active recovery-managed guests remain a separate case and must be stopped. Uninstall protection is unchanged. The Plugins console now prints the actual refusal reason. Do not delete ownership records to work around a refusal.

Before updating, finish/reconcile active work and pause replication schedules. Settled live-migration owners may remain running when the compatibility checks pass. Check native-fence readiness afterward. Live migration itself still needs matching beta5 versions on both hosts; otherwise-supported ordinary protocol-7 workflows retain their capability gates.

## Actual qualification

Both full verification suites passed on DEV01 **Unraid 7.3.4** and DEV02 **7.4.0**, including 69 new running-owner compatibility/lifetime/rejection checks, existing managed/recovery/boot guards and native package-manager tests. Package tests compare every embedded compatibility hash with the actual TXZ. No OS upgrade or production/outer-host access occurred.

Normal beta4-to-beta5 package upgrades were then performed with:

| Host | Already-migrated owners kept running |
| --- | --- |
| DEV01 | Linux file-disk and Linux zvol guests, with manually started integrity writers |
| DEV02 | Windows Server 2025 Core, software TPM 2.0 and active TPM-only BitLocker, with a manual-only writer |

Both fresh post-upgrade audits passed: original QEMU lifetimes, VM definitions/power states, ownership records, native admissions and job-history hashes were unchanged. Guest probes retained the Linux boots, writer tokens/PIDs, advancing writes and disk canaries. Windows retained its kernel boot event, uptime continuity, writer identity, TPM endorsement identity, BitLocker protectors/protection and OS/data canaries. No guest shutdown, forced resume, libvirt restart or authority adoption was used for the upgrade.

To test the old-copy fences rather than merely an absent UUID, the exact retained source definitions were temporarily restored while their real owners ran on the other host. **All three native start attempts were rejected for retired ownership before QEMU creation, both before and after the upgrades.** Only those temporary stopped definitions were subsequently unregistered; source disks, TPM/NVRAM, fences, backups and history were retained.

An intentionally incompatible test-only descriptor was also run through the native plugin installer on each host. It refused before package replacement or daemon stopping, displayed the specific reason on stdout, and left owner processes and installed descriptors unchanged. An actual beta5/beta4 paired preflight refused the version mismatch before creating a migration job or changing authority.

One private audit initially compared differently ordered hash maps strictly. A field-by-field diagnostic found no changed VM/process/authority/admission/file values; sorting the expected map in memory corrected the comparator, without editing its original baseline. A Windows read-only heartbeat probe also encountered the previously observed atomic-file sharing collision and passed on retry; no permissions or protection settings were changed. These are test-harness observations, not suppressed product failures.

The retained 0.5.1 package's eight native-fence dependencies were separately confirmed byte-identical to the beta5 target. That supports the compatibility contract; it is not a claim of a freshly executed 0.5.1-to-beta5 lab upgrade. Actual running upgrades used beta4, with file, zvol and Windows/TPM owners.

After both upgrades, a native file-disk round trip retained its original guest boot, manual writer and canaries. A separate Windows full stop/start preserved its TPM endorsement identity, BitLocker protectors, enabled protection and disk canaries; the manual-only writer did not autostart. Guest-agent availability exceeded the first polling window on that cold boot: the console showed the normal login screen, and the eventual agent probe passed without another restart or configuration/protection changes.

Final fresh audits found all test guests off, the file/zvol owners on DEV01 and Windows owner on DEV02, both arrays/native fences ready and both complete installed runtimes exact. The 19/17 unrelated VM definitions and states, 20/25 protected file hashes and 1/2 peer identities were unchanged. All 23 native job journals were terminal on each host; available current-boot disposable keys were revoked/quiesced with no private keys, native authorization lines, transfer workers, SSH clients, lease gates or custom relay processes left. Retained source data, backups, ownership fences and history remain intact.

Native migration performance, live Windows transfer and host-loss/reboot evidence are inherited from the unchanged beta4 engine, not freshly rerun in beta5. These upgrade checks do not certify every guest, local hook modification or failure window.

## Exact artifacts

The once-built candidate is published without rebuilding:

- Application `0.5.2-beta5`; fixed installer ID `2026.11.14.01`.
- TXZ SHA-256: `248f239292d97ba83b83a36f44610f8fc2749ceef1c4f320fbe52d022eac8470`.
- PLG SHA-256: `6db40aa7f61e5f6ab4bc151ce03fad9238f61924c1556a8f9f5e57197b7fe7ba`.
- Both installed runtimes: **118 files**, manifest `5907cd41cb8aa906ece006500be3824f10bce38f2b7e67a6b99bdc3b8515b649`.

Wire protocol 7 and live capabilities are unchanged. The [native trust model and guest/storage limits](NATIVE-TRUSTED-MIGRATION.md) still apply. This update does not remove old-copy fences, add automatic failover, allow an unresolved migration to upgrade, or qualify every historical/local modification of the native hook.
