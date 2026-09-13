# Verification

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
