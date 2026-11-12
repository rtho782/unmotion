// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
package main

import (
	"bytes"
	"encoding/json"
	"errors"
	"io"
	"os"
	"path/filepath"
	"reflect"
	"regexp"
	"runtime"
	"strings"
	"syscall"
	"time"
	"unicode/utf8"
	"unsafe"
)

const stateRoot = "/boot/config/plugins/unmotion"
const runRoot = "/run/unmotion/live"
const bootPath = "/proc/sys/kernel/random/boot_id"
const maxRecord = 8 << 20

var uuidRE = regexp.MustCompile(`^[a-f0-9]{8}(-[a-f0-9]{4}){3}-[a-f0-9]{12}$`)
var jobRE = regexp.MustCompile(`^live-[a-f0-9]{24}$`)
var hashRE = regexp.MustCompile(`^[a-f0-9]{64}$`)
var errLease = errors.New("live transport authorization withdrawn")

// Linux openat2 resolves the ENTIRE path with RESOLVE_NO_SYMLINKS in the
// kernel. This replaces repeated userspace walks, NOT fresh record reads.
// ABI: linux/include/uapi/linux/openat2.h, x86-64 syscall table entry 437.
// The fallback is also no-follow; no ordinary open() fallback is permitted.
func safeRead(path string, limit int) ([]byte, error) {
	if !filepath.IsAbs(path) || filepath.Clean(path) != path {
		return nil, errLease
	}
	name, e := syscall.BytePtrFromString(path)
	if e != nil {
		return nil, e
	}
	how := struct{ Flags, Mode, Resolve uint64 }{Flags: uint64(syscall.O_RDONLY | syscall.O_CLOEXEC | syscall.O_NONBLOCK), Resolve: 0x04}
	fd, _, errno := syscall.Syscall6(437, ^uintptr(99), uintptr(unsafe.Pointer(name)), uintptr(unsafe.Pointer(&how)), unsafe.Sizeof(how), 0, 0)
	runtime.KeepAlive(name)
	runtime.KeepAlive(how)
	if errno == 0 {
		return readOwnedFD(int(fd), limit)
	}
	if errno != syscall.ENOSYS && errno != syscall.EINVAL {
		return nil, errno
	}
	return safeReadWalk(path, limit)
}
func safeReadWalk(path string, limit int) ([]byte, error) {
	parts := strings.Split(strings.TrimPrefix(path, "/"), "/")
	fd, e := syscall.Open("/", syscall.O_RDONLY|syscall.O_DIRECTORY|syscall.O_CLOEXEC, 0)
	if e != nil {
		return nil, e
	}
	for i, p := range parts {
		flags := syscall.O_RDONLY | syscall.O_NOFOLLOW | syscall.O_CLOEXEC | syscall.O_NONBLOCK
		if i < len(parts)-1 {
			flags |= syscall.O_DIRECTORY
		}
		next, e := syscall.Openat(fd, p, flags, 0)
		syscall.Close(fd)
		if e != nil {
			return nil, e
		}
		fd = next
		var stat syscall.Stat_t
		if e = syscall.Fstat(fd, &stat); e != nil || stat.Uid != 0 {
			syscall.Close(fd)
			return nil, errLease
		}
	}
	return readOwnedFD(fd, limit)
}
func readOwnedFD(fd, limit int) ([]byte, error) {
	f := os.NewFile(uintptr(fd), "authority")
	defer f.Close()
	st, e := f.Stat()
	if e != nil || !st.Mode().IsRegular() || st.Size() > int64(limit) || st.Sys().(*syscall.Stat_t).Uid != 0 {
		return nil, errLease
	}
	if st.Size() > 0 {
		b := make([]byte, int(st.Size())+1)
		n, e := io.ReadFull(f, b)
		if (e != nil && e != io.EOF && e != io.ErrUnexpectedEOF) || int64(n) != st.Size() {
			return nil, errLease
		}
		return b[:n], nil
	}
	b, e := io.ReadAll(io.LimitReader(f, int64(limit)+1))
	if e != nil || len(b) > limit {
		return nil, errLease
	}
	return b, nil
}

type jobRecord struct {
	Schema      int    `json:"schemaVersion"`
	ID          string `json:"id"`
	VM          string `json:"vmUuid"`
	Source      string `json:"sourceHostId"`
	Destination string `json:"destinationHostId"`
	Role        string `json:"role"`
	Runtime     string `json:"runtime"`
	Boot        string `json:"bootId"`
	Expires     int64  `json:"expiresAt"`
	Token       string `json:"tokenHash"`
	State       string `json:"state"`
}
type authorityRecord struct {
	Schema      int     `json:"schemaVersion"`
	VM          string  `json:"vmUuid"`
	Job         string  `json:"jobId"`
	Source      string  `json:"sourceHostId"`
	Destination string  `json:"destinationHostId"`
	Role        string  `json:"role"`
	Boot        string  `json:"bootId"`
	Expires     int64   `json:"expiresAt"`
	State       string  `json:"state"`
	Disk        *string `json:"diskPath"`
	Workflow    string  `json:"workflow"`
}
type markerRecord struct {
	Schema int    `json:"schemaVersion"`
	VM     string `json:"vmUuid"`
	Job    string `json:"jobId"`
}
type accessRecord struct {
	Schema      int    `json:"schemaVersion"`
	ID          string `json:"jobId"`
	VM          string `json:"vmUuid"`
	Role        string `json:"role"`
	Boot        string `json:"bootId"`
	Key         string `json:"keyId"`
	Source      string `json:"sourceHostId"`
	Destination string `json:"destinationHostId"`
	Expires     int64  `json:"expiresAt"`
	ExpiresBoot int64  `json:"expiresBoot"`
	State       string `json:"state"`
}
type leaseGuard struct {
	base, run, boot, id, role, key string
	binding                        *jobRecord
}

// encoding/json replaces lone UTF-16 surrogate escapes; PHP's strict decoder
// rejects them. Preserve fail-closed parity for the WHOLE record, including
// ignored fields, without accepting a corrupt execution ledger.
func validEscapedUTF16(b []byte) bool {
	if !bytes.Contains(b, []byte(`\u`)) {
		return true
	}
	hex := func(p []byte) (uint16, bool) {
		if len(p) != 4 {
			return 0, false
		}
		var v uint16
		for _, c := range p {
			v <<= 4
			switch {
			case c >= '0' && c <= '9':
				v += uint16(c - '0')
			case c >= 'a' && c <= 'f':
				v += uint16(c - 'a' + 10)
			case c >= 'A' && c <= 'F':
				v += uint16(c - 'A' + 10)
			default:
				return 0, false
			}
		}
		return v, true
	}
	for i := 0; i < len(b); i++ {
		if b[i] != '\\' {
			continue
		}
		i++
		if i >= len(b) {
			return false
		}
		if b[i] != 'u' {
			continue
		}
		if i+4 >= len(b) {
			return false
		}
		v, ok := hex(b[i+1 : i+5])
		if !ok {
			return false
		}
		i += 4
		if v >= 0xdc00 && v <= 0xdfff {
			return false
		}
		if v >= 0xd800 && v <= 0xdbff {
			if i+6 >= len(b) || b[i+1] != '\\' || b[i+2] != 'u' {
				return false
			}
			lo, ok := hex(b[i+3 : i+7])
			if !ok || lo < 0xdc00 || lo > 0xdfff {
				return false
			}
			i += 6
		}
	}
	return true
}

// PHP ledger keys are case-sensitive and duplicate keys use their last value.
// Go's struct decoder otherwise folds case and ignores a later null scalar.
// Decode exact field names individually, never treating either as authority.
func strictRecord(b []byte, v any) error {
	var fields map[string]json.RawMessage
	if e := json.Unmarshal(b, &fields); e != nil || fields == nil {
		return errLease
	}
	r := reflect.ValueOf(v).Elem()
	for i := 0; i < r.NumField(); i++ {
		name := r.Type().Field(i).Tag.Get("json")
		raw, present := fields[name]
		if !present {
			continue
		}
		if bytes.Equal(bytes.TrimSpace(raw), []byte("null")) || json.Unmarshal(raw, r.Field(i).Addr().Interface()) != nil {
			return errLease
		}
	}
	return nil
}

func (g *leaseGuard) read(path string, v any) error {
	b, e := safeRead(path, maxRecord)
	if e != nil || !utf8.Valid(b) || !validEscapedUTF16(b) {
		return errLease
	}
	if e = strictRecord(b, v); e != nil {
		return errLease
	}
	return nil
}
func (g *leaseGuard) check() error {
	if !jobRE.MatchString(g.id) || !hashRE.MatchString(g.key) || (g.role != "source" && g.role != "destination") {
		return errLease
	}
	var j jobRecord
	if g.read(g.base+"/live-migration/jobs/"+g.id+"/job.json", &j) != nil {
		return errLease
	}
	if j.Schema != 1 || j.ID != g.id || !uuidRE.MatchString(j.VM) || !uuidRE.MatchString(j.Source) || !uuidRE.MatchString(j.Destination) || j.Source == j.Destination || j.Role != g.role || j.Runtime != g.run+"/"+g.id || !uuidRE.MatchString(j.Boot) || !hashRE.MatchString(j.Token) || j.Expires <= time.Now().Unix() {
		return errLease
	}
	switch j.State {
	case "DESTINATION_READY", "COPYING", "RECEIVED_PAUSED", "SOURCE_MOVING":
	default:
		return errLease
	}
	boot, e := safeRead(g.boot, 128)
	if e != nil || string(bytes.TrimSpace(boot)) != j.Boot {
		return errLease
	}
	host, e := safeRead(g.base+"/host_id", 128)
	if e != nil {
		return errLease
	}
	local := j.Source
	if g.role == "destination" {
		local = j.Destination
	}
	if string(bytes.TrimSpace(host)) != local {
		return errLease
	}
	var a authorityRecord
	var m markerRecord
	var k accessRecord
	if g.read(g.base+"/live-authority/"+j.VM+".json", &a) != nil || g.read(g.base+"/live-managed/"+j.VM+".json", &m) != nil || g.read(g.run+"/"+g.id+"/data-access.json", &k) != nil {
		return errLease
	}
	expected := "MOVING"
	if g.role == "destination" {
		expected = "RECEIVING"
	}
	if a.Schema != 1 || a.VM != j.VM || a.Job != j.ID || a.Source != j.Source || a.Destination != j.Destination || a.Role != j.Role || a.Boot != j.Boot || a.Expires != j.Expires || a.State != expected || a.Disk == nil || (a.Workflow != "" && a.Workflow != "ordinary") {
		return errLease
	}
	if m.Schema != 1 || m.VM != j.VM || m.Job != j.ID {
		return errLease
	}
	if k.Schema != 1 || k.ID != j.ID || k.VM != j.VM || k.Source != j.Source || k.Destination != j.Destination || k.Role != j.Role || k.Boot != j.Boot || k.Key != g.key || k.State != "ACTIVE" || k.Expires <= time.Now().Unix() || k.Expires > j.Expires {
		return errLease
	}
	var uptime syscall.Sysinfo_t
	if syscall.Sysinfo(&uptime) != nil || k.ExpiresBoot <= uptime.Uptime {
		return errLease
	}
	if old := g.binding; old != nil && (old.ID != j.ID || old.VM != j.VM || old.Source != j.Source || old.Destination != j.Destination || old.Role != j.Role || old.Runtime != j.Runtime || old.Boot != j.Boot || old.Expires != j.Expires || old.Token != j.Token) {
		return errLease
	}
	if g.binding == nil {
		g.binding = &j
	}
	return nil
}
