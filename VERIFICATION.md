# Reconstruction verification

Completed on 2026-08-09.

- The supplied PLG SHA-256 matches the historical release record: `20ace170707eaed65e55a7b2418e6e86fe5e98fbb48c1b5f2d5f509889e55656`.
- The TXZ extracted from that PLG is byte-identical to the separately supplied TXZ.
- The source tree extracted from the TXZ is byte-identical to `source/` in the separately supplied source tarball.
- All three original release artifacts pass `release/0.3.0-beta7/SHA256SUMS`.
- The embedded PLG package payload compares byte-for-byte with the retained release TXZ.
- Version `0.3.0-beta7` and peer protocol `5` agree across the source.
- Bash syntax checks pass for all shell executables and all repository build/test helpers.
- JavaScript parses successfully with Node.js `--check`.
- Static markers for ZFS receive resume, destination dedup configuration and the beta7 transfer-plan fix are present.
- The delivered ZIP was extracted into a clean directory and its file manifest was compared with the source repository package.

PHP CLI was not installed in the Windows reconstruction environment, so `php -l` could not be rerun here. The shipped source was not modified, and the original beta7 release record stated that PHP syntax checks passed. Re-run `scripts/verify.sh` on Unraid/Linux with PHP CLI before making or releasing code changes.

No live Unraid host or VM migration was invoked during reconstruction.

## RC1 development verification

`0.3.0-RC1` is the first post-recovery development release. Its verification is tracked separately from the immutable beta7 artifact record above. RC1 adds behavioral regressions for quoted Avahi TXT records, empty TSV cleanup fields, destination-unavailable resume classification, mutually exclusive rsync options, prepared-image re-protection after failed cutover and settings dirty-state reset. Live validation uses only the disposable `UNRAID-DEV01` and `UNRAID-DEV02` lab hosts.

## 0.3.1-RC2 development verification

RC2 retains peer protocol `5` and adds same-host full-copy VM cloning. Validation was performed on the disposable `UNRAID-DEV01` and `UNRAID-DEV02` hosts and on the Linux development VM.

- `scripts/verify.sh` passed with PHP, Bash and Node.js available. PHP behavior, shell behavior, static safety markers, PHP syntax, Bash syntax and JavaScript parsing all passed. ShellCheck also passed for the clone worker.
- The final package archive contains only `0755` directories. Installing it on both Unraid hosts left `/` at mode `0755`, preserving root SSH key authentication.
- Final candidate hashes are:
  - `unmotion-0.3.1_rc2-noarch-1.txz`: `e4b8324b4a00fc035d34bc81b62b5cce26bc18ef4414b37b3e7938bdc5220d7e`
  - `unmotion-0.3.1-RC2.plg`: `461cca4ff05010e403dd13c3b72227b555d407499d113d33fd1fe6ac68696f7a`
- The PLG author is exactly `Richard Skinner`.
- The official Ubuntu Server 26.04 ISO was installed on both hosts with SHA-256 `dec49008a71f6098d0bcfc822021f4d042d5f2db279e4d75bdd981304f1ca5d9`.
- The Ubuntu source VM uses static address `192.168.2.170/23`, has QEMU Guest Agent installed and active, and remained unchanged after cloning (inactive XML hash and source dataset GUID were checked).
- The Ubuntu clone received a new domain UUID, genid, NIC MAC, machine ID, hostname and OpenSSH host key. Its inherited static Netplan was archived and replaced with DHCP while its NIC was disconnected; it was left powered off after customization.
- Clone preflight and execution covered zvol, dedicated ZFS dataset, shared-dataset raw/qcow2 fallback, UEFI NVRAM, ISO retention, spaces in VM/dataset names, destination conflicts, running-source rejection and TPM rejection. Clone destinations were independent and stopped.
- Cold and warm migration regressions covered native ZFS datasets, zvols, shared raw/qcow2 files, sparse transfer, ISO copy/removal, UEFI NVRAM, software TPM state, overwrite authorization and multi-disk/multi-ISO plans. Warm cutover monitoring found no interval with the same VM running on both hosts.
- A real interrupted ZFS receive retained its resume token, source snapshot and hold; resume preserved the snapshot GUID and final removal cleared the exact partial destination, snapshot and hold.
- CSRF mutation blocking, self-pair rejection, reciprocal pairing, protocol `5`, destination running-UUID conflicts and all-hosts-powered-off cleanup were exercised.
- Cancellation at `GUEST_CUSTOMIZING` exposed and fixed three cleanup defects: a libvirt UUID probe incompatible with the lab libvirt CLI, premature success while a QEMU domain still owned its dataset, and an empty completed-snapshot ledger entry. The final rerun ended `CANCELLED`, verified every generated domain/storage/path target absent, and verified the source XML hash and dataset GUID unchanged.

The final DEV01 audit passed pairing/version checks, idle jobs and seeds, all-VMs-off checks, every qcow2 integrity check, the Ubuntu ISO checksum and absence of unMotion ZFS holds. The first DEV02 audit found 18 qcow2 refcount inconsistencies in the older disposable `QCOW2-Dedicated` VM after lab power-loss testing. Both of its migration-time, pre-first-boot ZFS snapshots were byte-identical to the healthy retained DEV01 source (`7f9cba12df7f502561733a3d6dba26740d5c113146853037ee71d5be3785a1ec`) and independently passed `qemu-img check`. The exact DEV02 dataset was rolled back to the newer verified snapshot, the VM booted stably, and the snapshot was restored again after the minimal guest ignored ACPI shutdown. The repeated DEV02 audit then passed every qcow2 integrity check as well as the same pairing, idle-state, all-VMs-off, ISO and ZFS-hold checks. The complete two-host regression therefore passed with all test VMs powered off.
