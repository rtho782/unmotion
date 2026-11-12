# Native live-data connector

Author: Richard Skinner

GPL-3.0-only. This Linux/amd64 helper is built from the adjacent Go source, with no external modules and `CGO_ENABLED=0`, for the x86-64 v1 instruction baseline. It is not a network server, a general file-copy command or a VM-start authority.

PHP keeps the pinned, forced-command SSH control plane, libvirt RPC validation, endpoint admission and supervision. This helper moves binary disk/RAM traffic through **already-approved inherited descriptors**:

| Descriptor | Purpose |
| --- | --- |
| 0 / 1 | Client input / output |
| 3 / 4 | Approved backend input / output |
| 5 | Supervisor lifeline; EOF revokes the connection |
| 6 | Optional monotonic byte-count telemetry, never mixed with payload |

It closes unrelated inherited descriptors, retaining Go runtime internal anonymous descriptors. It does not accept filesystem/socket destinations or shell commands. Every I/O iteration validates fresh, bounded, no-symlink job/authority/marker/key records, immutable job bindings, local host/boot identity and wall/boot-uptime expiry. Revoked authority is checked after polling, before I/O. Idle connections check within 250 ms; total data lifetime remains 30 minutes plus 120 seconds of grace. Buffers are bounded to 1 MiB per direction and individual I/O batches to 256 KiB.

Per-migration Ed25519 keys authorize only a fixed job-key gate; TCP/Unix forwarding, PTYs and arbitrary commands remain disabled. The existing pinned host key is still required. The receiver revokes new authentication and separately closes active sessions only after proving their current job-gate ancestry and exact process lifetime. Keys alone never authorize VM ownership or start. Source/destination ownership, durable native finish proof and paused-receiver fencing remain in the existing control plane.

`scripts/build-native.sh` requires Go (`UNM_GO` may name a private toolchain), builds a static helper with reproducible path/VCS/build-ID settings and embeds the application version. `scripts/build.sh` adds it and the toolchain's BSD licence to the package; `scripts/verify.sh` requires and runs its Linux tests. Users do **not** install Go. The initial qualification toolchain is the official `go1.27.4.linux-amd64.tar.gz`, SHA-256 `7226736c2339719cda28fbf3a54d71aa63bcefe376e98cef00034268dab24840`, obtained from the [Go release API](https://go.dev/dl/?mode=json). The Go runtime/source is available from the [official downloads](https://go.dev/dl/).

This implementation ships in **stable 0.5.0**, following owner-reviewed bounded DEV01/DEV02 qualification, including Linux interruption/retry and Windows TPM/BitLocker live/protected-cold-boot tests. See [results, exact artifacts and limits](../../docs/NATIVE-TRANSPORT-QUALIFICATION.md). No production-network or universal performance claim is made by the native unit tests alone.
