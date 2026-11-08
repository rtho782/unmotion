# CPU compatibility candidate verification

Author: Richard Skinner

## Status and scope

The initial **0.5.0-beta1 development candidate** was qualified on 2026-11-08 but was not released. These are historical discovery/cache results, superseded by the [0.5.0-beta2 qualification](TESTING-CPU-BETA2.md), which also covers local CPU configuration. Current availability is documented in the [CPU guide](CPU-COMPATIBILITY.md); the Community Apps installer remains stable-facing.

This work adds display-only CPU discovery, persistent observations and paired comparisons. It does not apply CPU definitions, start guests or implement live migration. Wire protocol remains 7; optional capability `cpuCompatibilityVersion: 2` identifies the cached-report operation.

## Automated verification

`scripts/verify.sh` passed in an isolated Linux checkout with PHP 8.5.4, Node.js 22.22.1 and Bash. Native Unraid plugin-manager checks used the existing 7.3.3 source fixture. No checks were reported skipped or unavailable. The existing development checkouts were preserved.

- 53 CPU-definition assertions: bounded input/XML, usable versus unknown models, exact expanded feature masks, rejected non-migratable clocks, vendor/machine context, canonical hashes and mixed/invalid peer responses.
- 60 persistent-cache assertions: one collection across repeated readers, read-only cold-cache requests, restart/boot-identity reuse, aliases, CPU/microcode/features and software-context invalidation, identity changes during collection, partial/unknown results, unavailable-host backoff, collector exclusion, corrupt records, clock rollback and redirected paths.
- JavaScript regressions: initial cache polling, all-peer comparisons, old/offline peers, local identity changes, canceled/late responses, escaped text and actionable validation errors.
- Existing syntax, API method/CSRF, restricted transport, pairing, inventory/UI, storage, migration, concurrent-worker and recovery/fencing regression suites passed.

## Disposable-host checks

UNRAID-DEV01 ran Unraid 7.3.3 and UNRAID-DEV02 ran 7.3.2. Both used QEMU 10.2.3 and libvirt 12.2.0. Their licensing USB devices were attached only to the corresponding lab VMs, and their arrays and VM Managers were started. All guest VMs stayed powered off.

1. Installed the candidate with the native plugin mechanism and checked packaged runtime files against the archive. Backups were retained on each lab host.
2. Tested a candidate host against the other host still running 0.4.9. The missing CPU capability produced clear upgrade guidance before dispatching the optional CPU request. Existing protocol-7 pairing remained intact.
3. With both candidates installed, `q35` resolved to `pc-q35-10.2`. Each host cached 30 validated levels; the paired operation returned 30 exact definitions in common in both directions. Other unconfirmed models were not promoted to supported.
4. Repeated authenticated paired reads preserved observation timestamps, definitions and report-file hashes. Local cached reads measured approximately 6 ms; three paired reads took approximately six seconds including SSH/capability checks. These are lab observations, not performance guarantees.
5. Attempting collection of an already valid context did not invoke the inspector. A missing context requested through the cached-only interface stayed unavailable and created no cache entry.
6. Refreshed the final unpublished candidate package and restarted unMotion. Cached report hashes, settings, host identities, pairing keys, libvirt hooks and guest power states remained unchanged; native fencing still reported ready. The normal upgrade guard refused a refresh during a transient inventory request and succeeded after it finished.
7. Tested the rendered DEV01 Settings panel. Invalid machine input displayed its validation error and cleared previous comparisons. Reading `q35` then showed 30 common rows; selecting `Skylake-Server-v3` displayed the matching definition status without changing any VM.

The final private candidate iteration was explicitly reinstalled after safety admission and its exact descriptor registered after every payload file was verified. This was not an overwrite of a published release. Production packages, VM configurations, update feeds and the Community Apps template were not changed.

## Candidate artifacts

SHA-256:

```text
b88105c0a68ec8baba50f0bb8a8cbad285aa8d01f1f68fdca49143b923721be1  unmotion-0.5.0_beta1-noarch-1.txz
943d59a5f342408a0087ea72f099301cf66a3b5a0a3f6403ab3830a2cfb561d2  unmotion-0.5.0-beta1.plg
```

## Limits

The labs are nested Intel guests, not independent AMD hardware. Earlier read-only Skylake/Cascade Lake observations are described in the [CPU guide](CPU-COMPATIBILITY.md), but no new physical CPU replacement or bare-metal AMD validation was performed. Boot reuse and invalidation were exercised by isolated tests; the final lab hosts were not rebooted solely to test cache persistence. Native package refresh and service-restart reuse were tested directly.

No guest CPU definition or migration behavior changed, and no guest migration or destructive storage regression campaign was needed for this display-only feature. A common cached mask is not authorization for an eventual live migration; fresh operational validation of the running guest and both hosts remains future work.
