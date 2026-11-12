// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
package main

import (
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"syscall"
	"testing"
	"time"
)

func writeJSON(t *testing.T, path string, value any) {
	t.Helper()
	b, e := json.Marshal(value)
	if e != nil {
		t.Fatal(e)
	}
	if e = os.MkdirAll(filepath.Dir(path), 0700); e != nil {
		t.Fatal(e)
	}
	if e = os.WriteFile(path, b, 0600); e != nil {
		t.Fatal(e)
	}
}
func fixture(t *testing.T) (*leaseGuard, map[string]any, map[string]any, map[string]any, map[string]any) {
	t.Helper()
	root := t.TempDir()
	g := &leaseGuard{base: root + "/state", run: root + "/run", boot: root + "/boot", id: "live-" + strings.Repeat("a", 24), role: "source", key: strings.Repeat("b", 64)}
	vm := "11111111-1111-4111-8111-111111111111"
	source := "22222222-2222-4222-8222-222222222222"
	dest := "33333333-3333-4333-8333-333333333333"
	boot := "44444444-4444-4444-8444-444444444444"
	expires := time.Now().Unix() + 1800
	j := map[string]any{"schemaVersion": 1, "id": g.id, "vmUuid": vm, "sourceHostId": source, "destinationHostId": dest, "role": g.role, "runtime": g.run + "/" + g.id, "bootId": boot, "expiresAt": expires, "tokenHash": strings.Repeat("c", 64), "state": "COPYING", "spec": map[string]any{"name": "Squid Proxy", "xml": strings.Repeat("x", 65536)}}
	a := map[string]any{"schemaVersion": 1, "jobId": g.id, "vmUuid": vm, "sourceHostId": source, "destinationHostId": dest, "role": g.role, "bootId": boot, "expiresAt": expires, "diskPath": "/mnt/cache/Squid Proxy/data.raw", "state": "MOVING"}
	m := map[string]any{"schemaVersion": 1, "vmUuid": vm, "jobId": g.id}
	var uptime syscall.Sysinfo_t
	if e := syscall.Sysinfo(&uptime); e != nil {
		t.Fatal(e)
	}
	k := map[string]any{"schemaVersion": 1, "jobId": g.id, "vmUuid": vm, "sourceHostId": source, "destinationHostId": dest, "role": g.role, "bootId": boot, "expiresAt": expires, "expiresBoot": uptime.Uptime + 1800, "state": "ACTIVE", "keyId": g.key}
	writeJSON(t, g.base+"/live-migration/jobs/"+g.id+"/job.json", j)
	writeJSON(t, g.base+"/live-authority/"+vm+".json", a)
	writeJSON(t, g.base+"/live-managed/"+vm+".json", m)
	writeJSON(t, g.run+"/"+g.id+"/data-access.json", k)
	if e := os.WriteFile(g.boot, []byte(boot), 0600); e != nil {
		t.Fatal(e)
	}
	if e := os.WriteFile(g.base+"/host_id", []byte(source), 0600); e != nil {
		t.Fatal(e)
	}
	return g, j, a, m, k
}
func TestFreshGuard(t *testing.T) {
	if os.Geteuid() != 0 {
		t.Fatal("Native guard tests require root-owned Linux fixtures")
	}
	for _, item := range []struct {
		record, key string
		value       any
	}{
		{"job", "state", "COMPLETE"}, {"job", "state", "ATTENTION"}, {"job", "vmUuid", "other"}, {"job", "role", "destination"}, {"job", "bootId", "stale"}, {"job", "expiresAt", 0}, {"job", "expiresAt", 1.5}, {"job", "runtime", "/run/unrelated"}, {"job", "schemaVersion", 2},
		{"authority", "state", "OWNER"}, {"authority", "state", "RETIRED"}, {"authority", "jobId", "other"}, {"authority", "vmUuid", "other"}, {"authority", "sourceHostId", "other"}, {"authority", "role", "destination"}, {"authority", "bootId", "stale"}, {"authority", "diskPath", nil}, {"authority", "workflow", "ordinary-pending"},
		{"marker", "jobId", "other"}, {"marker", "schemaVersion", 0}, {"key", "state", "REVOKED"}, {"key", "keyId", strings.Repeat("0", 64)}, {"key", "jobId", "other"}, {"key", "vmUuid", "other"}, {"key", "expiresAt", 0}, {"key", "expiresBoot", 0}, {"key", "bootId", "stale"},
	} {
		t.Run(item.record+"/"+item.key, func(t *testing.T) {
			g, j, a, m, k := fixture(t)
			if e := g.check(); e != nil {
				t.Fatalf("valid fixture: %v", e)
			}
			records := map[string]map[string]any{"job": j, "authority": a, "marker": m, "key": k}
			paths := map[string]string{"job": g.base + "/live-migration/jobs/" + g.id + "/job.json", "authority": g.base + "/live-authority/" + j["vmUuid"].(string) + ".json", "marker": g.base + "/live-managed/" + j["vmUuid"].(string) + ".json", "key": g.run + "/" + g.id + "/data-access.json"}
			records[item.record][item.key] = item.value
			writeJSON(t, paths[item.record], records[item.record])
			if g.check() == nil {
				t.Fatal("changed authority accepted")
			}
		})
	}
}
func TestGuardDoesNotCacheOrFollowLinks(t *testing.T) {
	for _, kind := range []string{"missing", "corrupt", "symlink", "directory-symlink", "host", "boot", "token", "utf8"} {
		t.Run(kind, func(t *testing.T) {
			g, j, _, _, _ := fixture(t)
			if e := g.check(); e != nil {
				t.Fatal(e)
			}
			path := g.base + "/live-migration/jobs/" + g.id + "/job.json"
			switch kind {
			case "missing":
				os.Remove(path)
			case "corrupt":
				os.WriteFile(path, []byte("{"), 0600)
			case "utf8":
				os.WriteFile(path, append([]byte(`{"bad":"`), 0xff), 0600)
			case "symlink":
				os.Rename(path, path+".old")
				os.Symlink(path+".old", path)
			case "directory-symlink":
				d := filepath.Dir(path)
				os.Rename(d, d+".old")
				os.Symlink(d+".old", d)
			case "host":
				os.WriteFile(g.base+"/host_id", []byte("other"), 0600)
			case "boot":
				os.WriteFile(g.boot, []byte("other"), 0600)
			case "token":
				j["tokenHash"] = strings.Repeat("f", 64)
				writeJSON(t, path, j)
			}
			if g.check() == nil {
				t.Fatal("stale/redirected/corrupt authority accepted")
			}
		})
	}
}
func TestQueueBoundedWrap(t *testing.T) {
	q := newQueue()
	for i := 0; i < 10000; i++ {
		space := q.room()
		n := min(len(space), 131071)
		for j := 0; j < n; j++ {
			space[j] = byte(i)
		}
		q.size += n
		take := min(q.size, 70001)
		q.consumed(take)
		if q.size < 0 || q.size > bufferSize || len(q.bytes()) > batchSize {
			t.Fatal("queue exceeds bound")
		}
	}
}
func TestSafeReadOwnershipAndFallback(t *testing.T) {
	g, j, _, _, _ := fixture(t)
	path := g.base + "/live-migration/jobs/" + g.id + "/job.json"
	a, e := safeRead(path, maxRecord)
	if e != nil {
		t.Fatal(e)
	}
	b, e := safeReadWalk(path, maxRecord)
	if e != nil || string(a) != string(b) {
		t.Fatal("safe fallback differs")
	}
	if e = os.Chown(path, 65534, 65534); e != nil {
		t.Fatal(e)
	}
	if g.check() == nil {
		t.Fatal("non-root record accepted")
	}
	_ = j
}
func TestStrictJSONKeys(t *testing.T) {
	for _, fragment := range []string{`"schemaVersion":null`, `"schemaVersion":"1"`, `"state":null`, `"state":0`, `"STATE":"COPYING","state":null`} {
		g, _, _, _, _ := fixture(t)
		p := g.base + "/live-migration/jobs/" + g.id + "/job.json"
		b, _ := os.ReadFile(p)
		b = append(b[:len(b)-1], []byte(","+fragment+"}")...)
		os.WriteFile(p, b, 0600)
		if g.check() == nil {
			t.Fatal("corrupt last scalar accepted", fragment)
		}
	}
	g, _, _, _, _ := fixture(t)
	p := g.base + "/live-migration/jobs/" + g.id + "/job.json"
	b, _ := os.ReadFile(p)
	b = []byte(strings.Replace(string(b), `"state":`, `"STATE":`, 1))
	os.WriteFile(p, b, 0600)
	if g.check() == nil {
		t.Fatal("case-folded authority key accepted")
	}
}
func TestUTF16Parity(t *testing.T) {
	for _, s := range []string{`{"v":"\ud800"}`, `{"v":"\udc00"}`, `{"v":"\ud800\u1234"}`, `{"v":"\ud800x"}`} {
		if validEscapedUTF16([]byte(s)) {
			t.Fatal("corrupt UTF16 accepted", s)
		}
	}
	for _, s := range []string{`{"v":"Squid Proxy"}`, `{"v":"\ud83d\ude80"}`, `{"v":"\\ud800"}`, `{"v":"\u00e9"}`} {
		if !validEscapedUTF16([]byte(s)) {
			t.Fatal("valid UTF16 rejected", s)
		}
	}
	g, _, _, _, _ := fixture(t)
	if e := g.check(); e != nil {
		t.Fatal(e)
	}
	p := g.base + "/live-migration/jobs/" + g.id + "/job.json"
	b, e := os.ReadFile(p)
	if e != nil {
		t.Fatal(e)
	}
	b = append([]byte(`{"ignored":"\ud800",`), b[1:]...)
	os.WriteFile(p, b, 0600)
	if g.check() == nil {
		t.Fatal("invalid ignored field was accepted")
	}
}
