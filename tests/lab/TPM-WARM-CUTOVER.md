# Manual TPM/UEFI Warm Move proof

Author: Richard Skinner

These fixtures run only inside a disposable Alpine/OpenRC guest named `beta2-tpm-live`. They are not part of the unattended verification suite. Use backed-up DEV01/DEV02 hosts, a fresh VM UUID/MAC and fresh software TPM state; never copy a production guest's TPM identity to construct the fixture.

Prerequisites: a bootable UEFI guest, TPM 2.0 emulator, kernel with the TPM CRB driver (`linux-lts` in this lab; `linux-virt` lacked it), QEMU Guest Agent, `tpm2-tools` and `tpm2-tss-tcti-device`, sufficient disk space and a working DHCP network. Disable inherited cloud-init metadata discovery in the disposable copy. Attach no ISO.

Install `tpm-warm-cutover-guest.sh` as `/usr/local/sbin/unmotion-tpm-proof` and `tpm-warm-cutover-service` as `/etc/init.d/unmotion-tpm-proof`, both executable. Run `init`, then `verify`, then enable/start the OpenRC service. Initialization refuses an existing proof directory. It creates a sealed random secret, a matching TPM NV value and a nonvolatile UEFI variable. Verification recreates the primary context instead of reusing a context saved before reboot. The writer synchronizes a counter and records it on graceful shutdown; it starts again on destination boot.

Prepare the running VM through unMotion. Check that Guest Agent freeze/thaw succeeds and that the destination remains undefined. After READY, write an additional file under `/var/lib/unmotion-tpm-proof` and record its checksum in `post-seed.sha256`. Cut over with retained-source policy, observing both hosts' VM states. Require source shutdown before destination running, verified final transfer, destination Guest Agent return, and completion of retained-source rename/autostart handling.

Run `verify` on the destination. Require the same unsealed-secret hash and TPM NV value, intact UEFI marker and post-seed checksum, a changed boot ID, and a counter greater than the recorded clean-shutdown counter. Keep the source off. At the end, shut down the destination and retain the fixture/evidence for inspection.

This tests unbound TPM-secret continuity and guest boot/state transfer, not Windows BitLocker, Secure Boot policy, PCR-bound sealing, or every guest OS. The sealing commands follow the upstream [tpm2-tools documentation](https://tpm2-tools.readthedocs.io/en/latest/man/tpm2_unseal.1/).
