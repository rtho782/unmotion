# Verification

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
