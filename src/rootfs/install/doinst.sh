#!/bin/bash
chmod +x /usr/local/sbin/unmotion-* /etc/rc.d/rc.unmotion 2>/dev/null || true
/etc/rc.d/rc.unmotion start >/dev/null 2>&1 || true
