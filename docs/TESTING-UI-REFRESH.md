# UI responsiveness checks — 0.4.6

Author: Richard Skinner

The initial UI campaign was tested on 2026-10-12 against stable 0.4.5 plus the local patch subsequently selected for 0.4.6. DEV01 ran Unraid 7.3.3 with 34 stopped VMs; its existing paired DEV02 ran 7.3.2. Production hosts were not contacted. Only DEV01's JavaScript and two page templates were patched, with original runtime files backed up. No migration, clone, replication or recovery operation was submitted and no VM was started.

## Browser findings and checks

| Area | Observation / result |
| --- | --- |
| Cold migration | Baseline dialog appeared after about 9.23 seconds. Patched dialog opened after about 50 ms and visibly waited for the same fresh checks (about 9.30 seconds total). Existing destination conflicts still blocked submission. |
| Local clone | Baseline dialog appeared after about 4.27 seconds. Patched dialog opened after about 31 ms; fresh checks completed after about 4.25 seconds. Editing the name disabled submission until recheck. |
| Warm Move cutover | Immediate loading dialog followed by the normal resource/source-handling configuration. No optical-media choices appeared for a VM without an ISO. Closed without submission. |
| Cancellation | Cancelled a cold-migration preflight while it was pending, then opened clone setup. The cancelled request did not reopen or overwrite the clone dialog. |
| VM inventory | Across repeated automatic scans and an explicit Refresh, existing rows/buttons retained DOM identity and previously loaded details never returned to the pending placeholder. Focus on an ISO information badge survived polling. |
| Other tables | Unchanged prepared-move, job, replication and incoming-replica buttons retained DOM identity across repeated polls. Before the patch, a job-table refresh interrupted one browser attempt to click Log. |
| Dialog fields | An edited clone name, its input element and keyboard focus survived background refreshes. |
| Logs | Baseline refresh forced a scrolled-up reader to the bottom. Patched refresh retained scroll position and selected text across multiple polls. |
| Replication / recovery / reporting | Their existing immediate setup/loading panels were checked. No configuration was saved, recovery action submitted or report uploaded. |
| Settings | Visible loading feedback already existed; the main settings request took about 1.44 seconds. Peer Test now immediately shows a disabled Testing button, then returns to Test with a successful connection message. The same button survived health/peer refreshes. |
| Browser errors | No JavaScript errors/warnings reported after final settings checks. |

Timing measurements describe this lab and include browser scheduling, not a performance guarantee. The improvement is immediate, persistent feedback and non-disruptive background rendering; operational preflight was deliberately not replaced with cached display data.

## Automated checks

The complete `scripts/verify.sh` passed on the Linux development VM, with root-only tests in an isolated mount namespace and the existing native Unraid plugin-manager fixture. No missing-tool/skip warnings were reported. Both page templates also passed PHP syntax checks on DEV01.

Added/extended dependency-free Node tests cover:

- Progressive first load; repeat-scan detail retention; fresh state overriding prior state; completed details clearing old data; errors and VM removal remaining visible.
- Keyed row/cell identity; zero cell writes for unchanged observations; changed cells, row reordering/removal and unchanged destination options.
- Immediate migration/clone loading; disabled submission; failed checks and retries preserving options; cancelled/superseded response isolation.
- Log single-flight reads, unchanged text, scrolled-up versus tail-follow behavior and obsolete responses.

Tested JavaScript SHA-256: `f9764e9759b3333b16f8b28462b9b373dd86aef8ba4b0910d64a28aa34be15e6`.

These are UI and automated safety regressions, not a new live migration/storage regression campaign. Backend operations and wire protocol remain unchanged. The subsequent 0.4.6 package/publication qualification is recorded in [VERIFICATION.md](../VERIFICATION.md).
