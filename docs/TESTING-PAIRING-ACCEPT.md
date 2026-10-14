# Pairing acceptance checks — 0.4.8

Author: Richard Skinner

Initial feature testing took place on 2026-10-14, based on 0.4.7 with the fingerprint-preview UI/API subsequently selected for 0.4.8. DEV01 (Unraid 7.3.3) received a backed-up runtime patch; DEV02 retained released 0.4.7. Release-package qualification is recorded separately in [VERIFICATION.md](../VERIFICATION.md).

- The DEV01 browser displayed the real DEV02 Ed25519 fingerprint with **Accept and pair / Cancel**, with no fingerprint-entry field. Manual verification instructions were collapsed and expanded correctly. Cancel restored the form without creating pairing state. Self-pairing displayed the existing rejection and kept acceptance disabled.
- Preview against the temporary DEV02 listener produced no password authentication attempts in its SSH log. Node tests additionally verify that the preview request contains only host and port, acceptance submits the exact displayed fingerprint once, pending/cancelled/stale responses cannot authorize pairing, and errors/mismatched endpoints remain blocked.
- PHP regressions verify preview creates no keys or records, validates addresses/ports, and rejects a key change between preview and pairing before password authentication or client-key creation. The new preview route requires a CSRF-protected POST; the existing Unraid token boundary is retained.
- Fresh reciprocal pairing passed on temporary port-22248 SSH listeners with private mount namespaces, separate plugin records and separate authorized-key files. A wrong fingerprint was rejected; the accepted preview fingerprint completed pairing with restricted forced-command keys and authenticated callbacks to the stock 0.4.7 peer. Existing installed peer records and keys on both hosts matched their pre-test hashes, as did DEV01's settings, host identity and native hooks.
- The first empty fixture timed out during the remote capabilities request. A rerun succeeded; the fresh fixture was then repeated with normal storage settings copied in to avoid first-run storage detection, and succeeded again. Both temporary listeners were stopped using verified process identities, leaving no listener on the test port. Private fixture artifacts were retained for inspection.
- The complete Linux `scripts/verify.sh` suite passed, including PHP, Bash, Node and privileged isolated tests, with no skipped-tool warnings. The process-test harness was adjusted for PHP 8.5's quoted diagnostic configuration path after the first run omitted its shared extensions. Installed web PHP checks remain unchanged.

No VM migration, cutover, storage deletion or VM start was performed. Production hosts were not contacted. This changes first-contact trust to explicit acceptance (trust on first use); it does not independently authenticate an accepted key unless the user performs the optional comparison. Existing pins, reciprocal host-key exchange and strict SSH checking remain enforced.

JavaScript SHA-256: `da03e7a97032e3466b901ee7f4b57c527cb0facf09e4c05c8a415c865e2f5204`.
