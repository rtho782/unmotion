# OVMF PCI address-space setting

Available in **stable 0.4.9 and later**. Author: Richard Skinner.

Restricted VM transport permits one specific QEMU firmware setting:

```xml
<domain type='kvm' xmlns:qemu='http://libvirt.org/schemas/domain/qemu/1.0'>
  <!-- Other VM configuration -->
  <qemu:commandline>
    <qemu:arg value='-fw_cfg'/>
    <qemu:arg value='opt/ovmf/X-PciMmio64Mb,string=65536'/>
  </qemu:commandline>
</domain>
```

This [OVMF option](https://github.com/tianocore/edk2/blob/master/OvmfPkg/RUNTIME_CONFIG.md) controls the 64-bit PCI MMIO address-space window, not the VM's allocated RAM. `65536` means 64 GiB. unMotion preserves the configured value; it does not add the setting or decide whether a guest needs it.

The command-line block must be a direct child of `domain` and contain exactly those two arguments. The optional `name=` prefix before `opt/ovmf/` is accepted. The value must be a canonical decimal integer from `0` through `16777216` MiB, matching EDK2's bound. Zero disables the window; choose an appropriate value for the VM and destination hardware. Extra arguments, duplicate command-line blocks, environment overrides, other firmware keys, `file=`/`gen_id=` sources, and appended options remain blocked. Ordinary Unraid XML metadata remains supported.

Install **0.4.9 or later on both hosts** before moving or replicating a VM that uses this option. Source preflight and workers check the destination's advertised `firmwareSettings.ovmfPciMmio64` capability and report an upgrade requirement before copying. Older sources still reject the setting themselves. Wire protocol 7 and existing pairing records are unchanged; ordinary VMs do not require this optional capability.

This exception does not enable PCI/GPU passthrough migration or relax disk ownership, host-path, firmware-content, source power-state, TPM/NVRAM or recovery-fencing checks. Do not remove a required guest setting or disable restricted transport to get past an error.

See the [automated and DEV01/DEV02 test record](TESTING-OVMF-FIRMWARE.md).
