# OVMF firmware exception verification

Date: 15 October 2026. Candidate test record for **0.4.9**, based on stable 0.4.8. Author: Richard Skinner. Final packaged-release checks are recorded separately in [verification](../VERIFICATION.md).

## Automated checks

- Complete `scripts/verify.sh` passed on the Linux development machine with PHP, Bash and Node available. Privileged tests ran in an isolated mount namespace. No missing-tool or skipped-test warnings were reported.
- The new firmware regressions passed 62 assertions on Linux and on both Unraid dev hosts. They cover the reported value, optional `name=`, numeric bounds, namespace aliases/default namespaces, ordinary metadata, unchanged XML, destination capability negotiation, and worker revalidation.
- Rejection cases include file/generator sources, appended options, extra arguments, environment variables, duplicate blocks, wrong namespaces, nested content, malformed/overflowing numbers and unrelated unsafe disk paths. Existing transport regressions passed 82 assertions on all three hosts; the Linux confined/sparse transport integration passed 21 assertions.

## Live lab checks

DEV01 runs Unraid 7.3.3 with 0.4.8 plus the candidate patch. DEV02 runs Unraid 7.3.2 with 0.4.7 plus the same narrowly applied patch. Original runtime files were backed up before patching. This is a temporary lab deployment, not a versioned package or a public feed change.

Before cutover tests, exact non-recursive snapshots of both outer lab VMs' boot/storage datasets were created. No other outer VM or production plugin configuration was modified.

| Test | Result |
| --- | --- |
| Patched DEV01 → stock DEV02, firmware-bearing VM | Migration and replication preflight rejected the missing firmware capability, before any seed was created |
| Stock DEV02 → patched DEV01 | Migration and replication preflight retained the old source's namespace rejection |
| Patched peers, zvol VM | Migration and replication preflight accepted the firmware setting |
| Patched peers, shared raw image | Migration accepted it; scheduled replication still rejected shared storage |
| DEV01 → DEV02 Warm Move | Native zvol preparation and update reached READY while source ran; cutover completed |
| DEV02 → DEV01 Warm Move | Shared raw image preparation and update reached READY while source ran; sparse-file cutover completed |

Both fixtures used names containing `Squid Proxy`, UEFI and software TPM, 512 MiB RAM and one vCPU. Prepared destinations remained read-only and had no VM definition. The blank firmware-only fixtures were explicitly powered off before final cutover; a known final disk delta was written after preparation. This validates powered-off final transfer, not guest-OS graceful shutdown or application consistency.

Both jobs reached COMPLETE, started only the destination, and retained/renamed the stopped source with autostart disabled. Destination XML retained the exact numeric firmware value, including the optional `name=` form. Final disk SHA-256 matched the source in both directions. UEFI/TPM host-state transfer and destination startup succeeded; TPM state remained present. Both destinations were stopped after verification, leaving all lab VMs powered off.

Settings, host identity, pairing keys/pins, peer connection configuration and existing native libvirt hooks matched their pre-test checksums. Peer observation metadata changed normally during fresh capability/health checks. Wire protocol remains 7. Production hosts' unMotion installations, public releases, installer feeds and published Community Apps guide were not changed.

These checks preceded versioned packaging. See the separate [release qualification and publication record](../VERIFICATION.md) for final package hashes, native upgrades and public feed verification.
