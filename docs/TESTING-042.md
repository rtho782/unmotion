# 0.4.2 verification evidence

Author: Richard Skinner.

The live campaign below exercised the unpublished `0.4.2-beta1` candidate that preceded stable 0.4.2. Its package names and hashes are historical test evidence, not the stable download checksums. Final general-availability build, installation and publication checks are recorded in [VERIFICATION.md](../VERIFICATION.md#042-general-availability--2026-10-04).

## Scope and environment

- Linux development VM: PHP, Bash, Node.js and isolated root namespace tests.
- Disposable UNRAID-DEV01 and UNRAID-DEV02: Unraid 7.3.2, renewed trials, existing reciprocal 0.4.1 pairing.
- Before destructive tests, exact outer DEV01/DEV02 disk datasets on Longcat were snapshotted separately as `@unmotion-042-before-security`. No other Longcat guests or production installations were changed.
- New `Beta042 Squid Proxy` fixtures cover shared sparse raw files, dedicated ZFS datasets, ZVols, Ubuntu with QEMU Guest Agent, ISO media, and native UUID-scoped UEFI/software TPM state. Migration guests have no NICs. The separate recovery fixture uses a new MAC and isolated NAT/DHCP, not the original guest's static address.
- Existing settings, host/peer identities and key material have hash baselines for upgrade-preservation checks. Ordinary lab replication policies were paused; two already-failed recovery-managed policies could not be paused through the normal API and their fencing records were left intact.

## Historical candidate validation — 2026-10-04

The complete Linux suite and final package installation checks passed on 4 October 2026. Required PHP, Bash, Node, root namespace and native Unraid plugin-manager checks were available; none were skipped. Focused totals include 81 transport unit, 21 transport integration, 34 native fence, 24 source-grant/control, 17 probe-controller and 46 managed-upgrade checks.

Verified:

- Native plugin-manager upgrades on both hosts, with exact settings, host/peer identities, private-key and known-host baselines preserved. Managed keys are restricted; administrator keys are not replaced.
- Both mixed-version directions fail closed. Fresh password bootstrap with new keys establishes reciprocal protocol-7 pairing; original pairing material was then restored and rechecked.
- Ordinary shell commands, root-file reads and alternate bootstrap/file-service commands are rejected by each managed key. Actual SSH forwarding attempts are administratively prohibited in both directions.
- Native ZFS filesystem/ZVol receive, mismatched type and compound-stream rejection, interrupted resume-token preservation/resume, incremental snapshot GUIDs and explicit receive properties on both hosts.
- Shared raw plus copied ISO, dedicated ZFS raw, and ZVol cold moves complete, with SHA-256 matching the baselines below. The 256 MiB rsync image remains sparse (about 16 MiB allocated) and receiver permissions are 0600.
- Ubuntu Warm Move: online guest freeze/thaw, read-only prepared copy with no destination definition, graceful shutdown, final incremental delta, destination startup and a responding guest agent. Source remains off and renamed under the retain policy.
- Failed cold jobs resumed their exact completed ZFS snapshots without deletion or retransmission, then completed. Source-off/destination-running checks passed.
- Native UEFI/software-TPM cold migration completes and starts the destination with its TPM. The transferred UUID-scoped NVRAM, TPM state and disk match their source hashes; the original source remains off.
- Reverse raw migration with explicit retained-destination overwrite completes. A subsequent forward move with source deletion finishes the full five-minute validation period and removes only the exact disposable source definition/disk; the running destination remains intact.
- Local ZVol cloning refuses a running source, then succeeds after that test source is stopped. The clone has a new UUID, is stopped with autostart disabled, and its independent disk hash matches the source.
- The preinstall guard refuses an upgrade while a real source-cleanup watcher is active, without signalling it or altering its process identity.
- Fresh scheduled replication transfers an initial and changed Ubuntu generation with guest freeze/thaw, inactive destination storage and verified snapshot GUIDs. Signed protocol-7 recovery-status calls succeed in both directions. Coordinated manual activation starts the latest generation with its guest marker/hash intact and a responding guest agent; normal stop-activation returns it to a stopped state.

The initial recovery test confirmed the previous boundary: the managed start gate refused a fenced source, but direct `virsh start` was only subject to post-start lifecycle fencing. The isolated test source was immediately stopped. The user then approved native pre-start fencing; subsequent checks are listed below.

## Native fencing live checks

- Both hosts invoke the separate `qemu.d` hook. The exact no-disk/no-NIC probe is rejected at PREPARE, before QEMU. Existing Unraid `qemu` hook bytes are unchanged, and the hook resides on each mounted persistent libvirt image.
- Fenced source starts and unmanaged direct restarts of a stopped activation are rejected before QEMU, with no new QEMU PID. Ordinary unmanaged TPM VM startup remains usable.
- The destination's normal start-activation workflow obtains a fresh grant-bound nonce, starts successfully, passes Guest Agent/data integrity checks and binds admission to the actual QEMU process lifetime. Normal stop clears admission; a later direct restart is refused.
- A separate source with managed autostart off refuses direct startup without a permit, then starts using the new one-off managed-start control. Its autostart preference remains false. The authorized VM survives a lifecycle-service restart.
- A graceful libvirtd-only restart preserves that authorized QEMU's exact PID and process start time. The source remains STANDBY/SOURCE; the callback proof refreshes for the new daemon. No VM restart, forced daemon kill, network restart or authority edit was required. The test's initial 15-second daemon-exit wait was too short; the daemon exited normally and was restarted with its captured arguments.
- Twenty authenticated remote status polls overlapping a queued source start preserve authority. Lock contention delays admission; the managed start retries and reaches RUNNING with an exact native process admission, without a persistent deadlock or an erroneous fencing transition.
- The actual Unraid installer refuses replacement while that managed source runs, leaving its authority, VM and lifecycle service intact.
- Copied-proof checks reject changed boot IDs, libvirt start ticks, non-daemon callers and changed hook/helper hashes. Global live proof was not altered for those negative tests.
- Live source startup exposed an autostart-parser pipeline failure: an early `awk` exit could terminate `virsh` under `pipefail`. Both gate and lifecycle now drain the output and distinguish `Autostart` from `Autostart Once`; behavioral regressions include a large pipe output, failed command and permit revocation.

Earlier lab-maintenance host-shutdown markers were preserved under `/tmp` while cancelling only that runtime maintenance intent. Durable policy holds and authority hashes were unchanged. No old recovery authority was cleared to make the tests pass.

## Historical final candidate artifacts

- TXZ: `unmotion-0.4.2_beta1-noarch-1.txz`, SHA-256 `525cd0b88313028e83fb7ba9deb2cf5045e881f4ad4a57587558a6bdb48c284b`.
- PLG: `unmotion-0.4.2-beta1.plg`, SHA-256 `421a40c172c8a2bae2e0afafa628eabde43ba8014569d91464728a6b0e98d2dc`.
- Both labs install the exact final archive; all 58 installed runtime files compare byte-for-byte. The original Unraid main hook is unchanged on both hosts. Native hook readiness and the exact lifecycle process identity are verified after installation.
- Because earlier unpublished candidates used the same package version, the final lab installation explicitly reinstalled the TXZ after executing the exact PLG preinstall guard. A forced PLG installation alone skips an already-installed identical package version. This is a lab-only step, not the published 0.4.1-to-0.4.2 upgrade procedure.
- Final-byte smoke tests on both hosts reject direct recovery starts before QEMU and refuse uninstall while recovery is unresolved. The normally disarmed local clone starts successfully and is stopped again. Both restricted pairings authenticate protocol 7 and reject arbitrary commands, file-service requests and actual SSH forwarding.
- Settings, host/peer identities, private-key and known-host baselines still match. All test guests are stopped. The new source-start test policy is normally disarmed and paused; the activation test remains stopped with its recovery authority intact. Lab scheduling remains paused and lifecycle fencing remains active. Pre-existing unresolved recovery policies have not been reset.

## Source integrity baselines

These are disposable test data, not user VM hashes.

| Fixture | SHA-256 before transfer |
| --- | --- |
| Shared raw disk | `f769fd7a136f6ae5de07268fff6868f76f446513b5e89a9a10a975687a6fa3a8` |
| Dedicated dataset raw disk | `8774b8350429fde9b95f5143f6f0475bf9797fff8371b12b7375e6e01ae16902` |
| ZVol | `160e9c40f51ff3c9bccbf8f1e91dcf597e7f41d766931e57959d1d7fb7a67922` |
| Alpine ISO | `e73a6241bd5f3c5c2d4d38c02cc52c378c0415a7c888bd292066bf36e0f41a39` |

## Candidate publication boundary

This candidate-validation campaign did not publish a release, tag, installer feed or Community Apps pin, and did not upgrade production packages. Stable 0.4.2 publication is a separate authorized step with distinct artifacts and checksums; see the general-availability record linked above.
