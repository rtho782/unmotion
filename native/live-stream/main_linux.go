// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
package main

import (
	"fmt"
	"os"
	"strconv"
	"strings"
	"syscall"
)

var appVersion = "development"

// proc_open deliberately duplicates the six approved handles. PHP extensions
// may also leave unrelated descriptors inheritable. Retain Go runtime epoll/
// eventfd internals, but never inherit a listener, payload endpoint or VM lock.
func closeInheritedFDs() error {
	entries, e := os.ReadDir("/proc/self/fd")
	if e != nil {
		return e
	}
	for _, entry := range entries {
		fd, e := strconv.Atoi(entry.Name())
		if e != nil || fd <= 6 {
			continue
		}
		target, e := os.Readlink("/proc/self/fd/" + entry.Name())
		if e != nil {
			continue
		}
		if strings.HasPrefix(target, "anon_inode:") {
			continue
		}
		_ = syscall.Close(fd)
	}
	return nil
}

func main() {
	if len(os.Args) == 2 && os.Args[1] == "--version" {
		fmt.Printf("{\"version\":%q,\"liveDataTransportVersion\":1}\n", appVersion)
		return
	}
	// Fixed inherited descriptors only: 0/1 client, 3/4 approved backend,
	// 5 supervisor lifeline, 6 optional count telemetry. Never opens a supplied
	// data path, spawns a shell/command, listens on TCP or grants VM ownership.
	if os.Geteuid() != 0 || len(os.Args) != 5 {
		fmt.Fprintln(os.Stderr, "unMotion native stream: invalid invocation")
		os.Exit(126)
	}
	if e := closeInheritedFDs(); e != nil {
		fmt.Fprintln(os.Stderr, "unMotion native stream: descriptor isolation failed")
		os.Exit(126)
	}
	mode, id, role, key := os.Args[1], os.Args[2], os.Args[3], os.Args[4]
	if mode != "duplex" && mode != "benchmark" {
		fmt.Fprintln(os.Stderr, "unMotion native stream: invalid mode")
		os.Exit(126)
	}
	guard := &leaseGuard{base: stateRoot, run: runRoot, boot: bootPath, id: id, role: role, key: key}
	if mode == "benchmark" && role != "destination" {
		fmt.Fprintln(os.Stderr, "unMotion native stream: invalid direction")
		os.Exit(126)
	}
	if err := runStream(mode, guard.check); err != nil {
		fmt.Fprintln(os.Stderr, "unMotion native stream:", err)
		os.Exit(1)
	}
}
