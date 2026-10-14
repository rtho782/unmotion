# Verification

## 0.4.8 release qualification — 2026-10-14

Author: Richard Skinner

The owner authorized stable publication of the fingerprint accept/cancel workflow. Application version is `0.4.8`, fixed installer version `2026.10.14.01-0.4.8`, protocol 7. Both installer feeds serve this stable release; Community Apps retains the exact stable installer URL. New pairing requires 0.4.7 or later on both peers, and the initiating host uses 0.4.8 for the dialog. Existing pairing identities and pins are retained.

The [feature test record](docs/TESTING-PAIRING-ACCEPT.md) covers the browser dialog, optional instructions, cancellation, self-pairing rejection, no password authentication during preview, key-change rejection and fresh reciprocal pairing using private lab SSH listeners. The tested JavaScript hash remains `da03e7a97032e3466b901ee7f4b57c527cb0facf09e4c05c8a415c865e2f5204`. First-contact acceptance uses trust on first use; independent comparison is optional, as documented. Strict key pinning, authenticated reciprocal host-key exchange, restricted client keys and the Unraid CSRF boundary remain enforced.

The complete Linux `scripts/verify.sh` suite passed again after the release metadata/build updates, including PHP, Bash, Node and privileged isolated tests. No missing-tool or skipped-test warnings were reported. DEV01 (Unraid 7.3.3) then passed a normal guarded native 0.4.7-to-0.4.8 plugin upgrade using the exact candidate descriptor and cached, SHA-256-verified TXZ. All packaged runtime files matched afterwards. Settings, host identity, pairing records/keys, existing libvirt hooks and every VM state matched the pre-upgrade baseline; native fencing remained ready. The current package was retained and the superseded cached download was pruned after successful registration, with a private pre-upgrade backup retained. DEV01 0.4.8 and unchanged DEV02 0.4.7 negotiated protocol 7 and returned healthy peer status in both directions over actual restricted SSH.

No VM was started, migrated or cloned during release qualification, and no production host was upgraded. Migration/storage workers, cleanup protections and recovery authority are unchanged. This qualification adds a packaged-upgrade check to the feature tests; it is not a new migration or reboot campaign. Release assets use a separate TXZ download with SHA-256 verification, without an embedded archive.

```text
5c63bf392490beaa4f9e449ebfc9c5ac968fe386542e0c9b53e4179e3e032692  unmotion-0.4.8_stable-noarch-1.txz
7eca5a30be9f988c6461e9707c7d2d36f6c085a6145a3932620fe31a5dedba51  unmotion-0.4.8.plg
```

### Public release verification — 2026-10-14

Tag `0.4.8` points to source commit `2b4e1fc34f9ab6cdf5244395a471a504f0165993`. The stable GitHub release is public with the tested TXZ, PLG and `SHA256SUMS`; public asset downloads match the hashes above. The TXZ was made publicly downloadable and verified before uploading the installer, and both feeds were advanced only after all public assets were verified. No tested package was rebuilt for publication.

Stable feed commit `53b9402eda98a31dc441da4cef830cd6a9609b56` and beta-graduation commit `30ec3abbffcf92d79a6b1556581408e6946b9fb0` serve the byte-identical released descriptor. Both passed `scripts/check-release-feed.php` against their previous descriptors before advancement. Exact raw installer URLs returned the expected SHA-256 from the desktop, and native `plugin check unmotion.plg` on both labs fetched that same descriptor and reported `2026.10.14.01-0.4.8`. DEV02 remained installed at 0.4.7 and offered the new update. One Linux curl client initially retained the preceding cached raw response; desktop and native updater verification used the advertised URLs without substituting a commit or query URL.

Community Apps commit `bad3dfdd968699451d4a58e983dae657b30d2ece` publishes the current guide, template and profile. Public source/CA guides, pairing guidance, XML/profile and feed READMEs match the prepared content. The template's `PluginURL` exactly equals the stable PLG's `pluginURL`; author remains `Richard Skinner` and minimum Unraid remains `7.3.2`. This verifies publication and lab update discovery; production installations and catalogue ingestion were not changed or asserted.

## 0.4.7 release qualification — 2026-10-13

Author: Richard Skinner

Application version `0.4.7`, fixed installer version `2026.10.13.02-0.4.7`, protocol 7. This release addresses initial SSH trust, VM dataset isolation/source cleanup and web mutation method enforcement. Release documentation and the companion guide were prepared against the current public `main` branches without changing the stable Apps installer URL. Publication checks are recorded separately after the assets and feeds are available.

The complete `scripts/verify.sh` suite passed on both disposable Unraid hosts with PHP, Bash and Node available, including native installer fixtures and privileged isolated tests. The process-service test now overrides Unraid's web prepend only inside its private test namespace; the installed web CSRF checks are unchanged. New regressions cover fingerprint rejection before password authentication, authenticated reciprocal pins, older-peer rejection with upgrade guidance, preservation of existing pairings, shared dataset classification, ownership changes, exact snapshot/hold checks, bounded busy retries and restoration of readonly/mount state. All 33 tested persistent mutation routes reject GET, HEAD, OPTIONS and a missing method; a valid POST reaches the history handler in the isolated endpoint test. Installed GET `clearJobs` also rejected the request and preserved the lab job records.

The owner authorized lab updates and destructive disposable fixtures. Existing outer snapshots were verified for all four DEV01/DEV02 storage disks before testing. Normal guarded upgrades from DEV01 0.4.6 and DEV02 0.4.5 preserved settings, host identities, SSH keys/pins and the existing Unraid hook. Existing 0.4.7/0.4.5 protocol-7 pairing passed before DEV02 was updated. Recovery-protected policy enabled states were preserved; the normal upgrade guard stopped verified idle scheduling services rather than changing recovery authority.

Live checks:

- Fresh reciprocal pairing passed using separate port-22247 SSH listeners with private mount namespaces, plugin records and authorized-key files. A wrong independently supplied fingerprint was rejected before password authentication. The successful exchange pinned the return key through the authenticated channel and installed restricted forced-command client keys. Existing installed pairing records were not replaced.
- `Security047 Squid Proxy Shared` contained an unrelated child dataset and ordinary file. It used sparse file transfer, copied neither unrelated item, and removed only its declared source disk after 300 seconds of destination runtime. Both unrelated items remained unchanged.
- `Security047 Squid Proxy Native` used native ZFS transfer. Its exact source dataset was removed without recursive destruction. The first candidate encountered a busy dataset held by the pairing test's private namespace; this exposed the need to restore mount state after a failed destroy. The revised implementation adds that restoration and bounded exact-object retries. Cleanup succeeded after releasing the test namespace and retrying the authenticated callback with completed validation evidence.
- `Security047 Squid Proxy Late` acquired unrelated contents and a child dataset after transfer. Cleanup was refused after the normal validation period, before source unregistration; its stopped definition, disk and unrelated data remained intact. Its exact test watcher was stopped after observing the refusal.
- `Security047 Squid Proxy Warm` prepared natively while its blank guest remained running. Cold preflight rejected that running source. The lab stopped this blank guest before cutover, wrote a final disk delta, then performed the prepared cutover and normal delayed source cleanup. Destination SHA-256 matched the saved final source hash: `26878c365fb5ed49076e66c7933ce01fa6c417a5acc8cb9afc8bf6188f82f068`. This test did not exercise graceful shutdown of an operating-system guest.
- Live held and foreign snapshots blocked source removal without deleting either snapshot. Exact job snapshot/zvol cleanup succeeded only after the test explicitly released/removed its own blockers. Both hosts passed opt-in native typed filesystem/volume receive, wrong-kind and compound-stream rejection, interrupted receive/resume, incremental GUID and explicit dedup/receive-property tests.

Existing automated coverage for discovery decoding/local-host refusal, reciprocal key restrictions, CSRF boundary, cold power state, destination conflicts, sparse transfer, ISO handling, UEFI/TPM, progress stdout isolation and recovery fencing also passed. A stale-pin regression additionally confirms that a failed verified-key write stops pairing before password authentication; the stored pin must match a full write and read-back check. No new browser UI campaign, real network interception attack or power-loss recovery campaign was performed. All lab guests were left stopped, temporary pairing listeners were removed, existing policy enabled states were unchanged and fixture storage/logs were retained for inspection. Production plugin installations were not changed.

Final tested artifacts:

```text
c840d78fb95640b379bc963213fbe028cd7bf40a33be2f5f4e6f32371df8cc6a  unmotion-0.4.7_stable-noarch-1.txz
05634976f1134bb3fa5158ca5775641b153b0bcbcdbc54fc386d9d34bb384f47  unmotion-0.4.7.plg
```

### Public release verification — 2026-10-13

The owner authorized publication. Tag `0.4.7` points to source commit `3c9af4b9544a9eb4f990fd9e274355e0b37eb500`. The stable GitHub release is public, with the tested TXZ, PLG and `SHA256SUMS`; public downloads match the hashes above. The TXZ download was verified before uploading the PLG or advancing either feed. No tested artifact was rebuilt for publication.

Both current feed descriptors passed `scripts/check-release-feed.php` before advancement. Stable feed commit `6d03810d4585add5edaf768eb3f55bbd1873519e` and beta graduation commit `e76c0e58d2e416b341a4e25a4200c229d98cc347` serve the byte-identical released PLG. Both exact raw installer URLs returned HTTP 200 and the expected SHA-256 after the initial cache refresh. Native `plugin check unmotion.plg` on DEV01 and DEV02 downloaded that same descriptor and reported `2026.10.13.02-0.4.7`; the earlier guarded upgrades from real older packages are documented above.

Community Apps guide/template/profile commit `8a076d35068e5e403e998fad821d6a9e03678e99` is published. Public guide, XML and security guidance match the prepared content. The template and PLG parse successfully, retain author `Richard Skinner` and minimum Unraid `7.3.2`, and have exactly equal stable installer URLs. The advertised release and guide pages are reachable. Catalogue ingestion and user installations were not asserted by these checks.

## 0.4.6 stable release qualification — 2026-10-13

Author: Richard Skinner

The owner authorized publishing the browser-tested UI changes as a new stable release. Application version is `0.4.6`; fixed installer version is `2026.10.13.01-0.4.6`. Protocol 7, migration/storage workers, recovery fencing and authentication behavior are unchanged. See the [UI test record](docs/TESTING-UI-REFRESH.md) for dialog timing, focus/tooltip preservation, log reading behavior, cancellation and automated regression coverage.

The complete Linux `scripts/verify.sh` suite passed again after the version/build updates, including PHP, Bash, Node and privileged tests in an isolated mount namespace; no missing-tool or skipped-test warnings were reported. The package contains the exact browser-tested JavaScript (`f9764e9759b3333b16f8b28462b9b373dd86aef8ba4b0910d64a28aa34be15e6`).

DEV01 (Unraid 7.3.3) passed a normal guarded native plugin-manager upgrade from 0.4.5 using the exact candidate descriptor and its checksum-verified cached TXZ. All packaged runtime files matched afterwards. Settings, host identity, pairing records/keys, existing libvirt hooks and all VM states were unchanged. Native fencing remained ready. The current package was retained; the superseded cached 0.4.5 package was pruned after successful registration, with a private pre-upgrade backup retained. The existing DEV02 pairing remained healthy with 0.4.6/0.4.5 negotiating protocol 7.

This release qualifies a UI-only change, not a new live migration/storage or reboot campaign. No VM was started, migrated or cloned during release testing; production hosts were not upgraded. Separate TXZ downloads, SHA-256 verification, the stable descriptor identity and the Community Apps stable `PluginURL` are retained. Both feeds graduate to this stable release; the accompanying user guide documents current behavior.

```text
9088e26e28f023f146d69b81b5dc8235b2a853971bec8cf1e7606f38240c0274  unmotion-0.4.6_stable-noarch-1.txz
270fcbf0c8d99e2a9656cf0938db63c798500c18370903ab2ba5ede127b248f9  unmotion-0.4.6.plg
```

## 0.4.5 stable release qualification — 2026-10-12

Author: Richard Skinner.

The owner authorized stable publication after additional testing on the newly licensed DEV02. The released application is `0.4.5`, the fixed installer version is `2026.10.12.02-0.4.5`, and protocol 7 is unchanged. The exact candidate TXZ and PLG recorded below are reused without rebuilding or replacing their tested bytes. Both feeds offer this stable installer; the Community Apps guide describes the current cached-inventory workflow and retains the exact `plugin-stable/unmotion.plg` template URL. The archive remains a separate SHA-256-verified release download, not embedded in the PLG.

The complete Linux verification suite and changed-script ShellCheck checks passed again for this release. All runtime source/build scripts match the tested candidate. New live mixed-version tests used the existing reciprocal DEV01/DEV02 pairing over actual restricted SSH, not a local dispatcher substitute: 0.4.5 and 0.4.3 negotiated protocol 7 and returned healthy peer status in both directions. The older peer correctly omitted the optional display capability. Fresh operational inventory retained its original contract, including names containing spaces. After DEV02's guarded 0.4.3-to-0.4.5 upgrade, the new cached view returned all 37 remote VMs in approximately 0.25 seconds per SSH request; fresh inventory remained separate.

DEV02 (Unraid 7.3.2) reproduced the old boot-time `plugins-error` failure and then passed a real clean reboot with 0.4.5. Its descriptor stayed registered, the cached package restored before the array started, the Starter licence remained valid and discovery advertised successfully. All 62 installed runtime files matched the tested TXZ after reboot. All 37 VM states, settings, host/pairing identities, private keys, authorized keys and existing libvirt hooks matched the pre-upgrade baseline. The normal periodic probe established native-hook readiness after VM Manager started, denying its exact disposable probe before QEMU; no manual service restart was needed. The browser showed all 37 VMs and healthy pairing, and Refresh retained those rows. DEV01 (7.3.3) had already passed the corresponding reboot, browser and disposable-VM lifecycle campaign documented below.

Testing remained within the two lab hosts and isolated Linux fixtures. No production host was upgraded or modified, and no new destructive migration/recovery campaign was required for the display-cache and installer changes. Production installation is left to the owner through the Plugins tab. The preceding candidate evidence below is historical; its references to DEV02 being unavailable and publication pending describe that earlier phase, not the final qualification.

## 0.4.5 unpublished candidate — 2026-10-12

Author: Richard Skinner.

Application version is `0.4.5`, with reserved installer version `2026.10.12.02-0.4.5`. This is a locally built and tested development candidate, not a published release or feed update. Protocol 7 remains unchanged; the optional `inventory-view` read is advertised by `inventoryViewVersion: 1`. Existing operational `inventory` and mutation-time checks remain fresh.

The complete Linux `scripts/verify.sh` suite passed with PHP, Bash, Node, the native Unraid 7.3.3 plugin-manager fixture and privileged mount/PID-isolated checks. ShellCheck warning-level checks passed for the changed shell scripts. New behavioral tests cover RAM-cache privacy and invalidation, boot/version rejection, singleton collection, progressive rows, failed refresh retention, proven empty inventory, mid-scan changes, linear XML inspection, exact peer RPC arguments, hidden tabs, single-flight polling and queued explicit refresh. Boot-restore fixtures cover actual boot ancestry, absence of running host services, normal upgrade exclusion and corrupt authority refusal.

DEV01 (Unraid 7.3.3) had 34 stopped VMs. Its original full inventory call took 35.159 seconds. The candidate exposed basic rows after 3.562 seconds and complete details after 4.566 seconds. Warm-cache backend reads averaged approximately 1.2–1.6 milliseconds; these are server-side measurements, not total browser page-load times. Eight simultaneous live readers each received all 34 rows, with the slowest taking 2.3 milliseconds and one collector observed. A disposable 64 MiB, diskless, networkless VM named `Beta045 Squid Proxy Inventory` exercised define, start, stop and removal. Real lifecycle events invalidated the cache; changes became visible within about 4–10 seconds, including refresh backoff. That test definition was removed; existing VMs stayed stopped.

The restricted dispatcher accepted `inventory-view` under an existing paired-key identity and rejected an unknown key and extra arguments. This was a local live dispatcher test, not a two-host SSH round-trip: DEV02 was unavailable, and Tacgnol was not paired or modified for this task. Existing transport/security regression tests passed. No destructive migration or recovery campaign was repeated.

DEV01's missing installation was traced to early boot, not Community Apps: the previous installer required libvirt to prove managed guests stopped before Unraid had started VM Manager, so Unraid moved the descriptor into `plugins-error`. The candidate preserves authority validation while recognizing verified `rc.local` boot restoration with no installed runtime, QEMU, libvirt or emhttp process. A real DEV01 reboot then restored the plugin from its checksum-verified cached TXZ before the array started; the descriptor remained registered, version 0.4.5 was present, the licence stayed valid and Avahi established the advertisement. With VM Manager unavailable the display reported an unavailable observation, not a successful empty inventory.

After starting the array normally, native fencing reported ready and all 34 VMs remained stopped. All 62 installed runtime files matched the tested TXZ. Settings, host identity, peer records/keys, authorized keys and the native hook matched their pre-upgrade hashes. The lifecycle listener remained running with valid libvirt event options. Browser checks confirmed 34 rows, observation status and retained rows during Refresh, with no unMotion JavaScript errors; unrelated Unraid Connect GraphQL errors were present. Production hosts and their VMs were not changed. Source and companion Community Apps documentation are prepared locally; publication remains pending.

```text
fea13e9f387860e4c68de661b06d74d79b66bb4a0dce9e219992a5c82b40ef8c  unmotion-0.4.5_stable-noarch-1.txz
32564189bc3799d64ee838d9481b6d07170391975c97ec4cf2c26b5c7e778001  unmotion-0.4.5.plg
```

## 0.4.4 discovery repair — 2026-10-12

Author: Richard Skinner

The complete Linux `scripts/verify.sh` suite passed with PHP, Bash, Node, the Unraid 7.3.3 plugin-manager fixture and privileged mount/PID-isolated tests. ShellCheck warning-level checks passed for the changed scripts. Discovery regressions cover atomic fresh creation under umask `077`, repair of an existing `0600` advertisement, repeated publication, fresh-file recreation, disabled discovery, failed write/chmod/rename cleanup, explicit daemon reload, names containing spaces and unchanged private permissions. The unfixed startup script fails the regression.

Live diagnosis found two interacting faults: Avahi could not read a root-only advertisement, and the former first-`pidof` reload targeted its chroot helper. The final package publishes an already-readable complete file by rename and uses `avahi-daemon --reload`. DEV01 (Unraid 7.3.3) passed a real 0.4.3-to-0.4.4 install with an initially root-only advertisement; the advertisement was established successfully and a separate 7.3.2/0.4.3 peer discovered it. Settings, host/peer identities, private keys, VM states and existing libvirt hooks were unchanged, and native-fence readiness remained valid without restarting libvirt.

The first unpublished test export exposed CRLF interpreter lines in executable hooks. It was never published or installed on production. DEV01 was restored from its verified 0.4.3 backup before repeating the final upgrade. Package sources now explicitly use LF and the builder/package tests reject CRLF interpreter lines. Compared with the released 0.4.3 archive, final runtime changes are limited to the discovery startup script and application-version constants; the GPL licence text is unchanged apart from line-ending normalization.

Application version is `0.4.4`, installer version is `2026.10.12.01-0.4.4`, and protocol 7 is unchanged. DEV02 was unavailable because its trial expired. No host reboot, browser-only campaign or new destructive migration/recovery campaign was performed for this discovery-only change; fresh boot-file creation and cached/offline installer paths were covered by isolated regressions.

```text
1bf4e34375a3dc45efd3551ee9a1a1b8080d290831c22b9cb20552b4965f3afd  unmotion-0.4.4_stable-noarch-1.txz
34484328abce2377db8c27bdf36e2a57eb31229f31f277bcf8979ad258bf5646  unmotion-0.4.4.plg
```

## 0.4.3 stable — 2026-10-04

Author: Richard Skinner.

Application version remains semantic (`0.4.3`); the fixed installer version is `2026.10.04.01-0.4.3`, bound by `RELEASE_ID`. Minimum Unraid is 7.3.2. Protocol 7 and the migration, clone, replication and recovery algorithms are unchanged from 0.4.2. Comparing the release archives found only two added cache-cleanup files and the version constants in `VERSION` and `include/lib.php`; existing runtime content is otherwise identical.

The complete Linux `scripts/verify.sh` suite passed with PHP, Bash, Node, native Unraid FILE-processor fixtures and privileged isolated process/transport checks; no required checks were skipped. New coverage includes 62 cache-cleanup assertions, 11 feed-admission cases, fixed release metadata, historical-version transition, same-day sequencing, repeated builds, double-digit beta/patch/minor ordering and independent Slackware package ordering. Fixtures verify unavailable/corrupt downloads, failed install/restart hook events, uncommitted registration, concurrent cleanup, redirected paths, links, unrelated/newer packages and repeated cleanup. Offline cached-package processing is tested with downloads disabled and command execution mocked; no fresh host reboot is claimed.

Both disposable Unraid 7.3.2 hosts passed real 0.4.2-to-0.4.3 upgrades. DEV01 used native `plugin install`; DEV02 used native `plugin update`, including its implicit feed re-fetch and package-URL validation. Before publication, DEV02 used a lab-only HTTP feed/asset URL fixture with the exact release TXZ; DEV01 used the exact release descriptor and cached package. The final release descriptor is registered on both hosts. Native testing exposed Unraid's omitted empty error argument; the hook was corrected and retested before release. DEV01 was restored to the exact older package to retest the final candidate's actual upgrade path.

DEV01 removed 16 older TXZ downloads; DEV02 removed 15. Each retained the verified current package, and all 60 installed runtime files matched the final archive. Boot-plugin backups were retained before cleanup. Settings, host/peer identities, private keys and existing Unraid hooks remained unchanged; protocol-7 pairing (including the interim 0.4.3/0.4.2 pair), exact lifecycle identity and native-fence readiness passed. All lab VMs remained stopped, scheduling remained paused and lifecycle fencing remained active.

This installer-only release does not repeat the destructive live VM migration/recovery campaign or a browser GUI test. That existing evidence remains applicable to the byte-identical application code. Production hosts were not modified.

```text
b9f36d22ddbeda2d45b7ed7e851df8039c22eef0973011d6b9c03a1ec38e56e0  unmotion-0.4.3_stable-noarch-1.txz
76ccf9c4f4cd82e26af3a065fe49fa4b6a2298441bbbbf5300abda6911c70012  unmotion-0.4.3.plg
```

GitHub reports [0.4.3](https://github.com/rtho782/unmotion/releases/tag/0.4.3) as latest, with draft/prerelease false; its annotated tag targets `c845a34b101fbab3c17d5eab3cb9acb6b157bd8c`. Downloaded assets and `SHA256SUMS` match the tested bytes, including an unauthenticated public TXZ download. Historical release assets remain unchanged.

Stable feed `8ed77c31d081cf24371d25edbe56f1775123f5d9` and beta-graduation feed `325670f7401e89f047cef036dd5ca19e6672fc38` serve the exact released descriptor at both branch and immutable URLs. Native `plugin check unmotion.plg` on both lab hosts returns `2026.10.04.01-0.4.3` and downloads the matching descriptor without starting scheduling or changing VMs.

Community Apps repository commit `01005d7abdc9ccb369b7efd7f7ce58b5908df9ae` publishes the updated guide, minimum 7.3.2 and verified stable manifest pin. Public README/template/profile copies, the pinned installer and source README match the committed files. This verifies publication, not Community Apps catalogue refresh timing. The temporary lab feed was stopped and both labs retain the byte-identical public descriptor.

## 0.4.2 general availability — 2026-10-04

Author: Richard Skinner.

The owner authorized stable publication of the tested protocol-7 security and native-fencing work. Detailed historical candidate evidence is in [docs/TESTING-042.md](docs/TESTING-042.md); its `0.4.2-beta1` hashes are not stable artifact hashes. Stable/application version is `0.4.2`, descriptor version is `0.4.2-stable`, and package token is `0.4.2_stable`. Both installer feeds graduate users to the stable update channel.

### General-availability artifact verification

The stable package was compared with the final tested candidate: the only changed runtime content is the application version in `VERSION` and `include/lib.php`. No migration, transport or recovery algorithm changed during promotion. Installer changes add the maintenance and native-start guidance and select the stable release identity/feed.

The complete Linux `scripts/verify.sh` suite passed with PHP, Bash, Node.js, root-isolated process/transport tests and the native Unraid FILE processor fixture; no required checks were skipped. Stable ordering tests cover both 0.4.1-stable and the unpublished 0.4.2-beta1 candidate.

Both disposable Unraid hosts passed normal candidate-to-stable `plugin install` upgrades. All 58 installed runtime files match the stable archive. Settings, host/peer identities, private keys and known-host baselines remain unchanged, as do the original Unraid hooks. Post-install checks verify current native callback proof, exact lifecycle identity, direct recovery-start rejection before QEMU, unresolved-recovery uninstall refusal, ordinary startup after normal disarming, protocol-7 peer authentication and SSH command/forwarding rejection. Test guests are stopped; lab replication scheduling remains paused with lifecycle fencing active. The earlier candidate campaign covered the 0.4.1 pairing upgrade and full migration/recovery matrix; this promotion did not repeat that entire destructive campaign or a browser GUI install test.

```text
07e4b14c715bb9aae09ffad661837eeb3c0a3c499368c86311ce7acec3261a13  unmotion-0.4.2_stable-noarch-1.txz
77f60ee17ce994f983e581e882a1ec7206cd70c317f312af2560a02a5e5c7d63  unmotion-0.4.2.plg
```

Publication order is the immutable versioned GitHub assets, verified downloads, matching stable/beta descriptors, then the Community Apps stable manifest pin and current user guide. Published files must match the hashes above. Production hosts are not upgraded by this publication task.

### Published endpoint verification

On 4 October 2026, GitHub reports [0.4.2](https://github.com/rtho782/unmotion/releases/tag/0.4.2) as the latest release, with both draft and prerelease flags false. The annotated tag targets source commit `6417c887268877d7d7108dcccb7c7fe57549a605`. Public downloads of both assets and `SHA256SUMS` match the verified build.

Stable feed commit `c19022116d767ff2f280e0205bf4cb2c704da90f` and beta-graduation feed commit `2463c6b33fc6c4d0885e8f17b171e33267c0bbee` both serve the exact released PLG through their branch URLs and immutable commit URLs. The [Community Apps repository](https://github.com/rtho782/unmotion-community-apps) commit `82a07ae57649babc71125edf64dcd28b99f6fca3` publishes the current guide/profile and pins that stable-feed descriptor. Public raw copies of its README, template and profile, the pinned installer and the source README were fetched and matched against the committed files. This verifies GitHub publication, not the timing of Community Apps catalogue refreshes.

## 0.4.1 stable promotion — 2026-09-13

Author: Richard Skinner

The owner reported successful beta2 update testing and authorized stable promotion. The stable package was compared with the refreshed beta2 package: only the application-version values in `VERSION` and `include/lib.php` differ. All other packaged file contents and permissions match. Descriptor metadata uses `0.4.1-stable`, package token `0.4.1_stable`, and the stable update feed; the embedded payload matches the standalone TXZ.

The full `scripts/verify.sh` suite passed on DEV01 and DEV02 with PHP, Bash and Node.js. Release-ordering regressions explicitly cover 0.4.0-stable and 0.4.1-beta1/beta2 advancing to 0.4.1-stable. Both disposable hosts then passed normal `plugin install` upgrades from beta2 using the exact stable descriptor named `unmotion.plg`. Configuration/host-identity/pairing hashes and all VM power-state snapshots remained unchanged. Application and installed descriptor versions/feed were verified, and each host had one supervised lifecycle daemon tree and one scheduler tree. The agent reported protocol 5 and parallel-cutover support after installation.

This promotion adds no migration/recovery algorithm changes. The beta2 live migration, running-guest TPM/UEFI and browser evidence below remains applicable; the stable promotion did not repeat that destructive/live-guest campaign, and its fresh lab upgrade checks used the CLI rather than a new Plugins-GUI test. Production installations and VMs were not changed by this promotion task.

Both public feeds offer the same stable descriptor, and the Community Apps guide/template are advanced to 0.4.1 as part of publication. Installing this stable descriptor graduates beta-feed installations to future stable updates.

Stable package SHA-256:

```text
b204544872403925e1c2ab64daf15851f44a2ddcd0817fe0dddc67e209322a0b  unmotion-0.4.1_stable-noarch-1.txz
b65fa3af17fe6f8d6e8b95451a6909cfb8425efcd047c05c277283a163ec45ce  unmotion-0.4.1.plg
```

## 0.4.1-beta2 cosmetic refresh — 2026-09-13

Author: Richard Skinner

At the owner's explicit request, beta2 was refreshed under the same version before reported external adoption. Its installed Plugins-tab description now uses an H4 heading instead of H1. Comparison of the rebuilt and original packages found exactly one changed file (`usr/local/emhttp/plugins/unmotion/README.md`); all other file contents and permissions match, including application code and the licence. Unraid's own Markdown renderer produced `<h4>unMotion</h4>`, the new heading regression passed, and the complete PHP/Bash/Node `scripts/verify.sh` suite passed on DEV01. No additional VM migration or package installation was needed for this description-only refresh.

The original source commit `edaf75f` and downloaded original assets are retained for audit. The beta2 source tag, release assets, checksum file and beta feed are refreshed together. This is an owner-authorized exception to immutable release policy, not a general same-version upgrade path. Existing beta2 installs will not detect the cosmetic change as an upgrade; older-version installation testing is still deferred to the owner. Stable 0.4.0 and the Community Apps stable installer selection are unchanged. The current Community Apps guide was reviewed and needs no guidance or availability change for this cosmetic correction.

Refreshed package SHA-256:

```text
68cbdcacbd8248540b58f4f3ca17207797b7ceab3dae21aafa07dbb4404ecaae  unmotion-0.4.1_beta2-noarch-1.txz
3922c1ab0166a77d2940a8addca2d67f730ec89feaf587eebf280b73602d65c5  unmotion-0.4.1-beta2.plg
```

## 0.4.1-beta2 candidate — 2026-09-13

Author: Richard Skinner

The complete `scripts/verify.sh` suite passed on both DEV01 and DEV02 with PHP, Bash and Node.js. New coverage includes warning/confirmation policy, preparation-only resource guidance, dependency-scoped health, OOM expiry, explicit archive-only requests, queued-job UI/backend guards, parallel-cutover eligibility, storage collisions, aggregate pending RAM, mixed-version exclusive fallback, and real shared/exclusive flock contention, cancellation and wake-up. A waiting job writes its waiting status once, not repeatedly to the boot device.

Before upgrading DEV02, beta2-to-beta1 preparation/update allowed an intentionally oversized startup RAM request as guidance while cutover rejected it; normal cutover preflight passed. Beta1-to-beta2 normal cutover preflight also passed and oversized startup RAM remained blocked. These were preflight checks, not mixed-version cutover transfers. Protocol versions are unchanged; parallel cutover requires the additive destination-start capability.

Both lab hosts received the candidate. Four stopped, disposable fixtures completed actual warm cutovers: `Beta41 Squid Proxy ZFS` and `Beta41 Squid Proxy Raw` toward DEV02, then the corresponding `Beta41 Reverse Squid Proxy` fixtures toward DEV01. Each pair had overlapping workers; both reverse workers entered preflight together after an exclusive test gate released. All four jobs completed, all destination domains reached running, and all four independently calculated destination disk SHA-256 hashes matched their modified source disks. Retain policy left each source stopped, renamed with the destination suffix and autostart disabled. Short disk transfers did not provide a sustained simultaneous-transfer throughput measurement.

An exclusive lab lock made a fifth, zvol-backed cutover enter `WAITING_FOR_MIGRATION` instead of failing. Cancellation passed first through the API function and then through the authenticated browser POST on the final build. Its source stayed stopped, its seed remained READY, and destination storage was not activated. Browser inspection confirmed Update, Cut over and Remove were disabled, Log remained available, Cancel was visible, and prepared-copy controls became available after cancellation. The no-ISO cutover dialog omitted optical-media choices. A never-started failure fixture archived through the UI without a destructive confirmation; archive-only safety and byte-preserving logs also have automated coverage. A content fingerprint on the main script URL prevents stale same-version candidate assets after page reload.

Pre-existing outer snapshots were verified read-only for both storage disks of each lab host before cutover testing. These are August baseline snapshots, not fresh snapshots from this campaign. The temporary DEV01 bandwidth setting was restored from 1 to its original 0; the cutover path itself is not rate-limited by that preparation setting. The four blank-disk destination fixtures were powered off after validation, with source and destination disks retained. The cancelled zvol prepared copy remains available. No production host, published release or public feed was changed.

Limits of the initial four-fixture campaign: these were initially stopped test VMs with blank disposable disks, not booted guest workloads. The follow-up below adds running-guest shutdown and TPM/UEFI cutover coverage. No fresh ISO-copy transfer, receive-abort, power-loss or delayed source-deletion campaign is claimed for this candidate; those retained paths have automated regression coverage and prior live verification below. Reservations are source-local, not distributed cross-source storage reservations. Cold moves, overwrite, attached-ISO copying and older-peer cutovers still use exclusive access with cancellable waiting.

### Running TPM/UEFI guest follow-up — 2026-09-13

A new disposable `Beta42 Squid Proxy TPM Live` guest (UUID `54200000-1111-4111-8111-000000000001`) completed a running Warm Move from DEV02 to DEV01 through the authenticated, CSRF-protected HTTP endpoints. It used Alpine 3.24.1, 2 vCPUs, 1 GiB RAM, a 2 GiB raw disk in its own ZFS dataset, custom dataset-contained UEFI NVRAM, a fresh software TPM 2.0 and QEMU Guest Agent. No ISO was attached. The copied base fixture remained stopped and unchanged; no production guest or identity was used.

Preparation `seed-9d407af529af88969736c025` froze/thawed one guest filesystem and completed READY while a guest service continuously wrote and synchronized a counter. Independent base-snapshot GUID checks matched (`10022592517848063667`), with destination storage read-only and no destination VM definition. A 32 MiB random file was then written after READY to exercise the final delta.

Cutover job `20260913-172837-9696ffa1a2` completed with parallel-cutover eligibility enabled. Its log records graceful source shutdown, the final incremental ZFS transfer (estimated 35,133,968 bytes), host-state transfer, mapped NVRAM checksum verification, destination start and retained-source renaming. Independent state samples observed source shut off before destination running and did not observe both running. This was one running guest, not an additional simultaneous-running-guest throughput test.

Guest Agent checks after destination boot passed all of the following:

- A sealed 32-byte secret unsealed to the original SHA-256, `5695bbefad272355592f4fc46c840b6151d0f906798a9de3e84414bb7fa8d433`. The primary context was recreated after boot, rather than trusting a saved transient context.
- TPM NV index `0x01500042` contained the same secret, and the custom nonvolatile UEFI marker retained its checksum.
- The post-seed file passed its independently recorded checksum.
- The boot ID changed from `30ac1cfc-c5ba-4cc2-87b8-b8429ad411e0` to `6f3b9e29-9537-4925-ab3a-69de2fe118a7`.
- The pre-cutover counter was 181, the service's clean-shutdown marker was 278, and the restarted destination service had advanced the counter to 417 at verification.

The original source was retained, renamed with the destination suffix and left shut off with autostart disabled. After verification the destination was gracefully shut down too; both copies and their storage remain available for inspection. The existing outer baseline snapshots were rechecked read-only before this campaign; no outer-host configuration or other VM was changed.

The complete `scripts/verify.sh` suite passed again on both hosts with PHP, Bash and Node.js, along with syntax checks for the new manual guest fixtures. No plugin runtime change was needed and the package hashes below are unchanged. The repeatable guest proof and its prerequisites are in [tests/lab/TPM-WARM-CUTOVER.md](tests/lab/TPM-WARM-CUTOVER.md).

This proves unbound TPM-secret/state continuity for this Alpine guest, not Windows BitLocker, Secure Boot/PCR-bound policies or every guest OS. The Plugins GUI upgrade-path test is explicitly deferred until beta publication, as requested. No release, update feed or production installation was changed; public documentation availability will be updated with publication.

Final local package SHA-256:

```text
99522791d3e5609bcb0504a48e0e381ca75b89483b81a3bc617ef4c1f93fec19  unmotion-0.4.1_beta2-noarch-1.txz
a14430cf3f4de8cc3e2a627e2271768f6debe1f4fcc0c609fa4da64f0c63bd30  unmotion-0.4.1-beta2.plg
```

## 0.4.1-beta1 never-started seed cleanup follow-up — 2026-09-13

Author: Richard Skinner

The complete `scripts/verify.sh` suite passed with PHP, Bash and Node.js. New archive regressions cover legacy never-started evidence, the explicit pre-storage flag and boot boundary, generation/pending/partial-state refusal, nonempty manifests, unexpected files, symlinks, active worker locks, unchanged archived file hashes and placement of the storage-started marker before snapshot/copy operations.

Installed on DEV01 and DEV02. On each host, both a legacy failed-removal fixture and a new explicit pre-storage failure passed through the actual `unmRemoveSeed` function with a deliberately unavailable pairing. Their records disappeared from active seeds, the logs remained byte-identical in archived-seeds, and all pre-existing prepared-copy record hashes were unchanged. No VM/disk/snapshot cleanup was performed in these integration tests. The new confirmation/result text passed JavaScript syntax checks; it was not separately exercised in the browser this follow-up.

Two owner-approved production records were separately archived with the tested standalone helper, after read-only confirmation of no matching source seed snapshots, destination objects or incoming seed records. Every archived file hash matched its original. The installed production plugin remained 0.4.0; there was no production upgrade, VM operation or storage deletion. Public release/update feeds were not changed.

## 0.4.1-beta1 concurrent warm-preparation follow-up — 2026-09-13

Author: Richard Skinner

The full `scripts/verify.sh` suite passed with PHP, Bash and Node.js, including new source/destination reservation, same-VM, retained/partial-state, peer-alias, path-descendant, hardlink, FUSE-alias, changed-mapping and explicit POST-gate tests. Real flock checks cover shared new-worker compatibility, exclusion of the older exclusive worker, and per-VM/per-seed ownership.

Three new stopped DEV01 fixtures (`Beta41 Squid Proxy ZFS`, `Beta41 Squid Proxy Raw`, and `Beta41 Squid Proxy ZVol`) prepared toward DEV02. All three were observed TRANSFERRING simultaneously with separate PIDs. A direct duplicate worker exited 5 without updating the active record. API-function update/resume/remove requests against that active seed were refused and its request-file hash stayed unchanged. All three preparations completed READY.

Two fresh DEV02 fixtures (`Beta41 Reverse Squid Proxy ZFS` and `Beta41 Reverse Squid Proxy Raw`) tested the final candidate in reverse. Their admission calls were submitted at the same time; both were accepted and transfers overlapped. Both completed READY. Initial DEV01 tests used the prior diagnostic-only candidate as receiver, requiring no new protocol command; the reverse tests exercised the final worker mapping guard.

Both raw-image copies passed independent source/destination SHA-256 checks. All three native-ZFS copies passed independent snapshot-GUID comparison and destination readonly checks. Every fixture's source remained stopped, and no destination libvirt domain was defined. The temporary 1 MiB/s per-worker test limit was restored to the original value (0) on both hosts. Five stopped source fixtures and their prepared copies were deliberately retained for further testing, without deleting any existing storage.

Limits: these tests used newly created stopped test VMs, not running guest workloads; no new freeze/thaw, cutover, update-pruning, deletion, power-loss or receive-abort campaign was performed. Collision/race edge cases beyond the duplicate active request were covered by automated guards rather than destructive live scenarios. Reservations are source-local, not distributed locks against unrelated source hosts. Production, public releases and both public update feeds remain unchanged.

## 0.4.1-beta1 unpublished diagnostic candidate — 2026-09-12

Author: Richard Skinner

The full `scripts/verify.sh` suite passed on DEV01 with PHP, Bash and Node.js, including the new diagnostic allowlist, identifier/secret redaction, original-path opt-in, bounded log-tail, linked-file rejection, XML entity rejection and source-file nonmutation tests. A browser-discovered pool-name/technical-label collision was fixed and covered by regression tests: a pool called `cache` no longer changes `cache size` or `driver/cache` field labels.

The candidate was installed on DEV01 and DEV02 only. Reports from existing lab jobs successfully included current CPU/vendor/features, memory/resources and software versions from both hosts. Before the peer upgrade, the optional command correctly fell back to recorded data. A real failed mixed-disk job was also reported through the authenticated Unraid UI without changing its state.

Browser checks passed report generation, disabled sharing before review, approval reset after edits, option-change invalidation, HTTP manual-copy fallback, the download action, generic GitHub draft creation and preservation of the edited local preview while GitHub was open. Closing/reopening cleared the preview. The draft URL and body contained no diagnostic content. No issue or attachment was submitted. GitHub was already signed in, so a logged-out sign-in round trip was not exercised. The downloaded file's bytes were not independently inspected. GET was rejected by the endpoint; an unauthenticated invalid-CSRF HTTP request was redirected by Unraid. An authenticated invalid-CSRF request was not separately exercised.

This was a read-only diagnostics campaign plus package installation, not a repeat of destructive migration/recovery tests. Transfer algorithms and protocol negotiation were unchanged. Production hosts, public releases and both published plugin-feed branches were untouched; the candidate remains on the development branch pending Community Applications review.

## 0.4.0

Author: Richard Skinner

Verification combines the release's live lab campaign, automated regression coverage and final packaging/upgrade checks. Tests used disposable UNRAID-DEV01/DEV02 guests. Production guests were not used for destructive testing.

### Automated checks

The complete scripts/verify.sh suite passed with PHP, Bash and Node.js available. Coverage includes syntax, pairing/protocol safeguards, cold-power-state gates, storage isolation, resumable transfers, host-state ownership, firmware equivalence, ISO collisions, cleanup boundaries and migration-resolution transactions. Release tests cover feed selection and Unraid plugin/package version ordering. Community Applications plugin/profile XML parsed successfully.

### Targeted live validation

- Cold custom-NVRAM file and dedicated-ZFS migrations completed, including retained-source renaming.
- A source-only firmware path mapped to byte-identical destination firmware. A same-name ISO with different contents was rejected; copying the correct ISO and verifying its checksum passed.
- Same-host custom-NVRAM cloning produced an independent, stopped clone with a new UUID-scoped variables file and disconnected NICs.
- TPM migration and exact source VM/dataset/TPM cleanup passed after the five-minute validation period.
- Warm Move passed on file and native-ZFS paths, covering retained-source rename and unregister-with-storage-retained policies.
- Initial, delta and shutdown-triggered custom-NVRAM replication passed, including Guest Agent freeze/thaw, safe powered-off checkpoint metadata, archive checksums, isolation rejection and retention pruning.
- An intentional destination startup failure entered Attention required. Resolution refused a stopped destination, then completed the selected policy after manual repair/start without changing the repaired destination XML.
- Manual resolution passed retain-and-rename, unregister and delayed-delete cases. Repeated completion did not repeat renaming or deletion. Lost-response and partial-action recovery paths also have automated coverage.
- Incompatible-peer preflight refused unsupported firmware/custom-NVRAM operations before transfer. Legacy protocol 5 and the separately negotiated protocol-6 recovery plane remain unchanged.

### Packaging and upgrades

Both lab hosts passed actual plugin check/update tests into stable 0.4.0. Configuration, host identity and pairing-file hashes remained unchanged. Each host had one lifecycle daemon, one scheduler and one installed unmotion.plg entry; lab VMs were left stopped.

The installed licence is GPL-3.0-only. The published package SHA-256 is:

```text
c7a8a5f7adbd8b799adb270256a0b8c70cb2da44777533eb7295eaac5d88c9b5  unmotion-0.4.0_stable-noarch-1.txz
```

The current installer SHA-256 is:

```text
4f394188394a92f60e31e1e9236e8af4c0ad8b6ad993de4527f8ef9dd1574929  unmotion.plg
```

The feed-branch naming change was verified as a URL-only descriptor edit with the package unchanged. Approved owner-host URL migrations were backed up, passed plugin checks and left daemon PIDs unchanged; they did not install packages or perform VM operations.

### Explicit limitations

Browser checks verified the displayed version, Plugins description and shared styled confirmation/cancel flow. The Resolve-specific dialog was not visually exercised. Custom-NVRAM recovery archive validation passed, but live recovered activation was not performed for that disconnected fixture.

The final stable packaging promotion did not repeat the full destructive power-loss campaign, clean installation, uninstall, reboot or browser matrix. Those are not claimed as fresh stable-promotion passes. Documented feature limitations and backup requirements remain applicable.
