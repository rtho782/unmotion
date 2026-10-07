#!/bin/bash
chmod +x /usr/local/sbin/unmotion-* /etc/rc.d/rc.unmotion 2>/dev/null || true
chmod 0755 /etc/libvirt/hooks/qemu.d/50-unmotion-recovery 2>/dev/null || true
/usr/local/sbin/unmotion-security-upgrade || exit 1
/etc/rc.d/rc.unmotion start >/dev/null 2>&1 || true
