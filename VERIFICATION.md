# Reconstruction verification

Completed on 2026-08-09.

- The supplied PLG SHA-256 matches the historical release record: `20ace170707eaed65e55a7b2418e6e86fe5e98fbb48c1b5f2d5f509889e55656`.
- The TXZ extracted from that PLG is byte-identical to the separately supplied TXZ.
- The source tree extracted from the TXZ is byte-identical to `source/` in the separately supplied source tarball.
- All three original release artifacts pass `release/0.3.0-beta7/SHA256SUMS`.
- The embedded PLG package payload compares byte-for-byte with the retained release TXZ.
- Version `0.3.0-beta7` and peer protocol `5` agree across the source.
- Bash syntax checks pass for all shell executables and all repository build/test helpers.
- JavaScript parses successfully with Node.js `--check`.
- Static markers for ZFS receive resume, destination dedup configuration and the beta7 transfer-plan fix are present.
- The delivered ZIP was extracted into a clean directory and its file manifest was compared with the source repository package.

PHP CLI was not installed in the Windows reconstruction environment, so `php -l` could not be rerun here. The shipped source was not modified, and the original beta7 release record stated that PHP syntax checks passed. Re-run `scripts/verify.sh` on Unraid/Linux with PHP CLI before making or releasing code changes.

No live Unraid host or VM migration was invoked during reconstruction.

## RC1 development verification

`0.3.0-RC1` is the first post-recovery development release. Its verification is tracked separately from the immutable beta7 artifact record above. RC1 adds behavioral regressions for quoted Avahi TXT records, empty TSV cleanup fields, destination-unavailable resume classification, mutually exclusive rsync options, prepared-image re-protection after failed cutover and settings dirty-state reset. Live validation uses only the disposable `UNRAID-DEV01` and `UNRAID-DEV02` lab hosts.

## 0.3.1-RC2 development verification

RC2 retains peer protocol `5` and adds same-host full-copy VM cloning. Validation was performed on the disposable `UNRAID-DEV01` and `UNRAID-DEV02` hosts and on the Linux development VM.

- `scripts/verify.sh` passed with PHP, Bash and Node.js available. PHP behavior, shell behavior, static safety markers, PHP syntax, Bash syntax and JavaScript parsing all passed. ShellCheck also passed for the clone worker.
- The final package archive contains only `0755` directories. Installing it on both Unraid hosts left `/` at mode `0755`, preserving root SSH key authentication.
- Final candidate hashes are:
  - `unmotion-0.3.1_rc2-noarch-1.txz`: `e4b8324b4a00fc035d34bc81b62b5cce26bc18ef4414b37b3e7938bdc5220d7e`
  - `unmotion-0.3.1-RC2.plg`: `461cca4ff05010e403dd13c3b72227b555d407499d113d33fd1fe6ac68696f7a`
- The PLG author is exactly `Richard Skinner`.
- The official Ubuntu Server 26.04 ISO was installed on both hosts with SHA-256 `dec49008a71f6098d0bcfc822021f4d042d5f2db279e4d75bdd981304f1ca5d9`.
- The Ubuntu source VM uses static address `192.168.2.170/23`, has QEMU Guest Agent installed and active, and remained unchanged after cloning (inactive XML hash and source dataset GUID were checked).
- The Ubuntu clone received a new domain UUID, genid, NIC MAC, machine ID, hostname and OpenSSH host key. Its inherited static Netplan was archived and replaced with DHCP while its NIC was disconnected; it was left powered off after customization.
- Clone preflight and execution covered zvol, dedicated ZFS dataset, shared-dataset raw/qcow2 fallback, UEFI NVRAM, ISO retention, spaces in VM/dataset names, destination conflicts, running-source rejection and TPM rejection. Clone destinations were independent and stopped.
- Cold and warm migration regressions covered native ZFS datasets, zvols, shared raw/qcow2 files, sparse transfer, ISO copy/removal, UEFI NVRAM, software TPM state, overwrite authorization and multi-disk/multi-ISO plans. Warm cutover monitoring found no interval with the same VM running on both hosts.
- A real interrupted ZFS receive retained its resume token, source snapshot and hold; resume preserved the snapshot GUID and final removal cleared the exact partial destination, snapshot and hold.
- CSRF mutation blocking, self-pair rejection, reciprocal pairing, protocol `5`, destination running-UUID conflicts and all-hosts-powered-off cleanup were exercised.
- Cancellation at `GUEST_CUSTOMIZING` exposed and fixed three cleanup defects: a libvirt UUID probe incompatible with the lab libvirt CLI, premature success while a QEMU domain still owned its dataset, and an empty completed-snapshot ledger entry. The final rerun ended `CANCELLED`, verified every generated domain/storage/path target absent, and verified the source XML hash and dataset GUID unchanged.

The final DEV01 audit passed pairing/version checks, idle jobs and seeds, all-VMs-off checks, every qcow2 integrity check, the Ubuntu ISO checksum and absence of unMotion ZFS holds. The first DEV02 audit found 18 qcow2 refcount inconsistencies in the older disposable `QCOW2-Dedicated` VM after lab power-loss testing. Both of its migration-time, pre-first-boot ZFS snapshots were byte-identical to the healthy retained DEV01 source (`7f9cba12df7f502561733a3d6dba26740d5c113146853037ee71d5be3785a1ec`) and independently passed `qemu-img check`. The exact DEV02 dataset was rolled back to the newer verified snapshot, the VM booted stably, and the snapshot was restored again after the minimal guest ignored ACPI shutdown. The repeated DEV02 audit then passed every qcow2 integrity check as well as the same pairing, idle-state, all-VMs-off, ISO and ZFS-hold checks. The complete two-host regression therefore passed with all test VMs powered off.

## 0.4.0-beta1 development verification

Beta1 retains migration protocol `5` and adds independently negotiated scheduled-replication protocol `1`. Validation used only the disposable `UNRAID-DEV01` and `UNRAID-DEV02` hosts plus the Linux development VM.

- `scripts/verify.sh` passed with PHP, Bash and Node.js available. PHP and shell behavioral regressions, PHP/Bash syntax, JavaScript parsing and static safety markers all passed; the package build also passed its directory-mode audit.
- Final candidate hashes are:
  - `unmotion-0.4.0_beta1-noarch-1.txz`: `7f2f77c09ba4abdb6c59387e91420dcc7a540a9720bf75f26c0a1c81b2f7922e`
  - `unmotion-0.4.0-beta1.plg`: `44b5e6f5724f2aaeabefb8a4132fcc9bd9b25dd6937dd725e0e654982e9e2924`
- Replication passed for a dedicated raw/qcow2 dataset, a zvol, and a mixed two-disk consistency group with spaces in VM and dataset names. Reverse-direction replication also passed. Destination filesystems remained `readonly=on`, `canmount=off`, `mountpoint=none`; destination zvols remained `readonly=on`, `volmode=none`, `snapdev=hidden`; no replica was defined in libvirt.
- Ubuntu QEMU Guest Agent freeze/thaw and network evidence passed with the static address `192.168.2.170`. Only usable unicast addresses were retained, and the verified point was not marked replication-only. Points without Guest Agent evidence remained explicitly replication-only.
- A real interrupted 768 MiB ZFS receive retained the exact pending source snapshot, hold, GUID and destination resume token. Restarting the scheduler resumed the same generation and committed matching source/destination GUIDs without discarding partial state.
- Removing an exact committed base snapshot hard-blocked the next incremental send while retaining its pending generation. Restoring the exact base allowed the same generation to resume and commit. A foreign destination hold produced `CLEANUP_FAILED`, blocked newer generations, preserved the new current point, and succeeded after only the test hold was released.
- TPM/NVRAM coverage included a running-VM stun checkpoint labelled `best-effort`, a power-cycle checkpoint labelled `safe`, SHA-256 verification on the destination, and a subsequent best-effort point referencing only the compatible safe TPM/NVRAM checkpoint. Unraid's aliased swtpm paths were treated as one directory; genuinely distinct paths remain a hard blocker.
- The in-app UI displayed the beta1 non-activation boundary, outgoing policies and incoming inert inventory. The ten RPO notches rendered correctly; retention clamped from 24 points at a five-minute RPO to one point at 24 hours. Shared storage was blocked with ZFS Master guidance, removal was gated, log viewing worked, and a UI `Run now` completed through Unraid's CSRF-protected request path.
- Shared datasets were rejected for replication while remaining available to the existing migration fallback. A final 3.0 GiB dedicated-dataset cold migration completed from DEV01 to DEV02, verified the transferred storage, defined and started the destination, retained and renamed the stopped source, and exercised the existing migration worker after the SSH stdin-isolation change.
- Live testing exposed and fixed whitespace-sensitive ZFS hold parsing, control SSH consuming a following multi-disk plan row, stale runtime state overriding a newly queued run, unsafe source cleanup ordering, generic base-loss diagnostics, swtpm alias false positives, incompatible or nested safe TPM fallback metadata, and stopped destination libvirt definitions colliding with incoming replica UUIDs.
- Final release-candidate review added behavioral regressions requiring transferred device-complete safe checkpoints, preserving every retained primary/fallback archive while deleting only exact unreferenced content-addressed archives, and case-insensitive UUID collision detection with a second check immediately before destination inventory commit. The complete Linux verification suite passed again after these changes.
- The rebuilt package was reinstalled on both disposable Unraid hosts. A final manual DEV01-to-DEV02 zvol generation advanced the policy from generation 19 to 20, published the new point, retained one point as configured, and completed archive cleanup with no orphan archives. The repeated two-host audit found every policy `IDLE`, every incoming manifest `READY`, all VMs powered off, both schedulers alive, no receive tokens and no defined incoming-replica UUIDs.

The beta1 limitation remains deliberate: TPM/NVRAM promotion is opportunistic when a scheduled or manual run observes the VM powered off; beta1 has no libvirt lifecycle watcher, activation, failover, quorum, managed autostart or failback. All test VMs were powered off after the final regression.

## 0.4.0-beta2 development verification

Beta2 preserves legacy pairing, migration, cloning and scheduled replication protocol `5`, advertises a compatible `5`-to-`6` range, and negotiates protocol `6` only for recovery protocol `1`. Validation used only the disposable `UNRAID-DEV01` and `UNRAID-DEV02` hosts plus the Linux development VM.

- `scripts/verify.sh 0.4.0-beta2` passed with PHP, Bash and Node.js available after the final retention and recovery-UI changes. PHP behavioral regressions, shell behavioral regressions, PHP/Bash syntax, JavaScript parsing, static safety markers and recovered-artifact hashes all passed.
- Final candidate hashes are:
  - `unmotion-0.4.0_beta2-noarch-1.txz`: `29dd98577a13f32dbe08111e1c27096e7b21710aeabe9e707f7b0afbd2833245`
  - `unmotion-0.4.0-beta2.plg`: `2f9c501855ab70a0042f0b1049eae5ca687636ea4e2a14caa9d01ce680c7fce4`
- The packaged PLG author entity is exactly `Richard Skinner`. Installing that PLG on both hosts replaced the old lifecycle/scheduler PIDs, preserved the exact source and destination recovery-state hashes, left the recovery-managed source VM off, and settled to one lock-owning lifecycle daemon plus one scheduler per host.
- Protocol-range, HMAC envelope, signed-response, reciprocal-host identity, Ed25519 secret-bootstrap, tamper, replay, wrong-host, duplicate-policy and mixed-version recovery gates have PHP behavioral coverage. Version-5 operations remain available when recovery negotiation is unavailable; recovery fails closed unless both peers explicitly negotiate protocol `6` and recovery protocol `1`.
- Arming/disarming, native-autostart disable/verification, authenticated coordinated grant, authority-term transfer, exact claim binding and expiry, hold/reply-loss reconciliation, claim renewal, activation removal, source-start fencing and legacy-operation interlocks were exercised or behaviorally regressed. Only one recovery policy may hold authority for a VM UUID.
- The persistent lifecycle daemon detected powered-off transitions and launched opportunistic TPM replication. A lock-inheritance defect that could prevent the daemon starting after synchronous reconciliation was fixed by closing the singleton descriptor in background workers and the libvirt event listener. Unarmed VMs no longer generate managed-state-unavailable notifications; armed policies wait for sustained uncertainty and fail closed.
- A DEV02 Ubuntu VM with QEMU Guest Agent and software TPM produced a powered-off, Guest-Agent-eligible point with a `safe` transferred TPM checkpoint. With configured retention `1`, a deliberately newer Guest-Agent-ineligible point was published; DEV01 retained both the newest replication-only point and the older eligible point. The extra safety point disappeared once the normal retention set again contained an eligible point.
- Coordinated recovery initially rejected two QGA-reported IPv6 ULA addresses that DEV01 could not safely prove silent. The guest was corrected to report only its intended IPv4 address; no probe rule was weakened. The next grant moved authority to DEV01 at term `2`, while DEV02 remained shut off, native autostart disabled and durably fenced.
- DEV01 activated the exact claimed point from a separate ZFS clone, installed the selected UUID-scoped TPM checkpoint, started the VM and certified QEMU Guest Agent health. The exact-checkpoint reinstall/retry path then replaced the TPM state crash-safely and certified health again. Stopping and removing the activation deleted only its domain, activation-owned ZFS clone and TPM directory; the retained point, snapshot GUID and exact unMotion hold remained intact.
- Browser confirmation actions use accessible in-page `alertdialog` overlays rather than native confirmation boxes. Both host pages were cache-bypassed and the styled arm, disarm, stop, retry and removal dialogs were exercised. Recovery mutation controls are hidden while a durable operation is queued or running.
- Backward compatibility was live-tested by cloning an unarmed zvol VM locally and cold-migrating that clone from DEV02 to DEV01 over the protocol-5 path. The destination started with the new clone UUID, no source definition remained, and the exact smoke domain, unheld snapshots and source/destination zvols were removed non-recursively after validation.
- The final two-host audit found every scheduled replication policy `IDLE`, every incoming manifest `READY`, no ZFS receive token, no running VM, and exactly one lifecycle daemon and one scheduler per host. The recovery test pair remains intentionally fenced at destination authority after activation removal; beta2 never restores source authority implicitly.

The beta2 boundary remains deliberate: automatic failover, an unreachable-source two-host claim, witness voting, alternate-checkpoint selection and failback transfer are not advertised or accepted. Cold failback is preflight-only.

## 0.4.0-beta3 verification — 2026-09-12

Author: Richard Skinner

This is a focused custom-NVRAM migration regression, not a repeat of beta2's full destructive recovery campaign. The Linux development VM ran scripts/verify.sh with PHP, Bash and Node available: recovered hashes, PHP syntax and behavioral tests, shell syntax and behavioral tests, JavaScript parsing and static invariants all passed. New filesystem tests cover pool-path mapping, escaped XML, old-peer capability refusal, missing variables, ambiguous sibling mappings, disk collisions, symlinks/hardlinks, live/inactive VM ownership, unavailable inventory, destination collisions and SHA-256 mismatches.

Live tests used only disposable UNRAID-DEV01 and UNRAID-DEV02 after restoring their trial licenses and starting their arrays. Outer storage reference snapshots were confirmed present before migration/overwrite tests. Fresh 512 MiB Alpine fixtures used names containing Squid Proxy, retained disconnected NICs and did not touch production guest data.

- Nine migrations completed: custom-NVRAM cold ZFS and shared-file copies; standard libvirt NVRAM cold transfer; custom-NVRAM Warm Move with native ZFS and file-copy seeds; reverse native ZFS overwrite; standard-NVRAM migration to an actual beta2 destination; and repeat native/file cold transfers with the final ownership/XML guards installed.
- Both custom paths verified the final NVRAM SHA-256 before destination definition. Dedicated datasets used the existing non-recursive ZFS transfer, and shared-file migration explicitly copied the variables file after disk transfer. XML paths changed between cache and zfspool. Destination UEFI boot into Alpine was inspected by a libvirt screenshot.
- Warm Move seeded while running and completed after graceful source shutdown. A concurrent second cutover was rejected by the existing single-worker lock; resuming it after the first job completed succeeded.
- Retained source definitions remained stopped with autostart disabled and a migrated-name suffix. Reverse ZFS overwrite used existing matching-UUID ownership authorization.
- With DEV01 genuinely running beta2 and DEV02 beta3, custom-NVRAM preflight refused the missing beta3 capability. A standard-NVRAM VM migrated successfully from beta3 to beta2. DEV01 was then restored to beta3.
- Running-source cold preflight rejection and the real Unraid invalid-CSRF rejection were checked. Existing resume-token, held-snapshot, pairing/discovery, ISO, UEFI/TPM and progress-stream safeguards passed the automated suite; power-loss and replica-activation tests were not repeated for this migration-only patch.
- Successive same-version lab candidates were explicitly reinstalled at the package level and installed source markers checked, because forcing descriptor installation alone does not replace an equal-version Slackware package.

Final release assets:

- PLG SHA-256: ddf36bff7d3486b256ddf598372bab9c54c05680de4e4c31dde296f935078647
- TXZ SHA-256: ea326490641de6eca469250377bb5ef13699f60eeb14e0984913cefd486c7f19

The descriptor adds Unraid's pluginURL for the dedicated published-beta feed. Post-publication check/update results are recorded in the GitHub release notes. Production hosts are to remain on beta2 with only a backed-up descriptor metadata bootstrap, leaving the user to choose Update in the Plugins tab.
