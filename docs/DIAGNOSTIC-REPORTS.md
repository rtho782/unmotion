# Diagnostic problem reports

Author: Richard Skinner

## Creating a report

Choose **Report a problem** on any migration or clone job, including running, failed and attention-required jobs. Reporting does not cancel, resume, resolve or otherwise change the job.

Choose whether to retain original paths and whether to request current destination host specifications. Generate, review and edit the preview, then confirm that you have reviewed it. Download/copy uses the edited text. Changing options regenerates the report; editing clears the confirmation. Closing discards the in-memory preview. Reports are not stored in browser storage or on the server.

**Open GitHub issue draft** sends only a generic title/version and instructions to the user's browser. Logs, paths and host/VM identifiers are not placed in the URL. The user logs into GitHub if needed, attaches the downloaded report or pastes its text, and submits there. The unMotion window remains available through login. Without a GitHub account, download the report and share it through an available support channel. No unattended upload, GitHub token or reporting service is used.

## Contents and privacy

- Selected job state, times, options and recent migration log (at most 128 KiB, with truncation marked).
- Projected saved source/destination XML: memory, vCPUs, CPU model/topology/features/pinning, machine/firmware, disk formats/paths/controllers, NIC model and passthrough types. Raw XML and arbitrary QEMU arguments are never exported.
- Recorded disk and resource-plan numeric evidence, including capacities/sizes when the job recorded them. The collector does not open disks or run qemu-img against running VM images; missing virtual-size evidence remains unavailable rather than inferred from qcow2 file length.
- Current source host CPU model/vendor/features and resources, memory, NUMA and software versions. Optional current destination specs use the existing SSH pairing and a read-only command with an eight-second timeout. Older peers, unavailable peers or a failed command fall back to clearly labelled job-era metadata; that is not a current liveness check.

Default report-local aliases replace recognised VM/host identifiers and user path components consistently, while retaining path structure, file extensions and spaces where practical. The alias map is never exported. Original-path mode retains potentially identifying paths but does not disable secret filtering.

Only explicit job filenames and technical fields are read. Symlink/hardlink files, unexpected paths, oversized structured inputs and XML entity declarations are rejected/omitted. Logs receive secret-line, URL, key, address and identifier filtering. Guest contents, disk/NVRAM/TPM contents, credentials, pairing keys, arbitrary environment data and raw host configuration are not collected. All files and arrays have bounds; version commands have timeouts. Running jobs may change during capture, so the report is not an atomic machine snapshot.

Redaction is best-effort, not guaranteed anonymity. Free-form logs can contain unexpected identifying text, and users can add identifying text while editing. The UI requires review and warns that GitHub issues and attachments are public. No screenshot or report is sent automatically.

## Release boundary

Diagnostic reports are available in stable 0.4.1. Existing protocol 5 operations and the negotiated recovery plane are unchanged; diagnostic-host is a read-only optional command, with graceful fallback on older peers.
