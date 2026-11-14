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

For the historical restricted FD transport, Ed25519 keys authorize only a fixed job-key gate, without TCP/Unix forwarding, PTYs or arbitrary commands. Current trusted native live mode uses a separate administrator-equivalent libvirt/forwarding grant; do not describe it as this per-path boundary. Both modes keep strict host pins, revocation and exact session-lifetime checks. Keys alone do not authorize a duplicate VM start; the current ownership/control semantics are documented in [native trust](../../docs/NATIVE-TRUSTED-MIGRATION.md).

`scripts/build-native.sh` requires Go (`UNM_GO` may name a private toolchain), builds a static helper with reproducible path/VCS/build-ID settings and embeds the application version. `scripts/build.sh` adds it and the toolchain's BSD licence to the package; `scripts/verify.sh` requires and runs its Linux tests. Users do **not** install Go. The initial qualification toolchain is the official `go1.27.4.linux-amd64.tar.gz`, SHA-256 `7226736c2339719cda28fbf3a54d71aa63bcefe376e98cef00034268dab24840`, obtained from the [Go release API](https://go.dev/dl/?mode=json). The Go runtime/source is available from the [official downloads](https://go.dev/dl/).

This connector was introduced in **stable 0.5.0** following bounded DEV01/DEV02 qualification; see [its historical results/artifacts](../../docs/NATIVE-TRANSPORT-QUALIFICATION.md). Current **stable 0.5.2** retains the helper for native data/benchmark operations, but new trusted live jobs carry bulk traffic directly through OpenSSH/libvirt rather than this Go forwarding path. See [the current trust model](../../docs/NATIVE-TRUSTED-MIGRATION.md) and [stable promotion scope](../../docs/RELEASE-0.5.2.md). Native unit tests alone make no universal performance claim.
