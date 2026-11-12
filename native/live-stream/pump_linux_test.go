// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
package main

import (
	"bytes"
	"fmt"
	"io"
	"os"
	"os/exec"
	"strings"
	"syscall"
	"testing"
	"time"
)

func TestPumpChild(t *testing.T) {
	if os.Getenv("UNM_NATIVE_TEST_CHILD") != "1" {
		return
	}
	if e := closeInheritedFDs(); e != nil {
		fmt.Fprintln(os.Stderr, e)
		os.Exit(9)
	}
	alive := func() error {
		v, e := os.ReadFile(os.Getenv("UNM_NATIVE_TEST_LEASE"))
		if e != nil || string(v) != "ACTIVE" {
			return errLease
		}
		return nil
	}
	if e := runStream(os.Getenv("UNM_NATIVE_TEST_MODE"), alive); e != nil {
		fmt.Fprintln(os.Stderr, e)
		os.Exit(9)
	}
	os.Exit(0)
}

type streamChild struct {
	cmd                         *exec.Cmd
	input, life, backend, count *os.File
	output, err                 bytes.Buffer
	lease                       string
}

func startPump(t *testing.T, mode string) *streamChild {
	t.Helper()
	lease := t.TempDir() + "/lease"
	if e := os.WriteFile(lease, []byte("ACTIVE"), 0600); e != nil {
		t.Fatal(e)
	}
	fds, e := syscall.Socketpair(syscall.AF_UNIX, syscall.SOCK_STREAM|syscall.SOCK_CLOEXEC, 0)
	if e != nil {
		t.Fatal(e)
	}
	b := os.NewFile(uintptr(fds[0]), "backend")
	remote := os.NewFile(uintptr(fds[1]), "remote")
	inR, inW, _ := os.Pipe()
	lifeR, lifeW, _ := os.Pipe()
	countR, countW, _ := os.Pipe()
	exe, e := os.Executable()
	if e != nil {
		t.Fatal(e)
	}
	s := &streamChild{input: inW, life: lifeW, backend: remote, count: countR, lease: lease}
	s.cmd = exec.Command(exe, "-test.run=^TestPumpChild$")
	s.cmd.Env = append(os.Environ(), "UNM_NATIVE_TEST_CHILD=1", "UNM_NATIVE_TEST_LEASE="+lease, "UNM_NATIVE_TEST_MODE="+mode)
	s.cmd.Stdin = inR
	s.cmd.Stdout = &s.output
	s.cmd.Stderr = &s.err
	s.cmd.ExtraFiles = []*os.File{b, b, lifeR, countW}
	if e = s.cmd.Start(); e != nil {
		t.Fatal(e)
	}
	for _, f := range []*os.File{b, inR, lifeR, countW} {
		f.Close()
	}
	t.Cleanup(func() { s.input.Close(); s.life.Close(); s.backend.Close(); s.count.Close() })
	return s
}
func finishPump(t *testing.T, s *streamChild, success bool) {
	t.Helper()
	done := make(chan error, 1)
	go func() { done <- s.cmd.Wait() }()
	select {
	case e := <-done:
		if (e == nil) != success {
			t.Fatalf("unexpected exit: %v %s", e, s.err.String())
		}
	case <-time.After(5 * time.Second):
		s.cmd.Process.Kill()
		t.Fatal("native pump failed to stop")
	}
}
func TestFullDuplexAndHalfClose(t *testing.T) {
	s := startPump(t, "duplex")
	payload := bytes.Repeat([]byte{0, 0xff, 0x56, 0x91}, 2<<20)
	response := bytes.Repeat([]byte{0x71, 0, 0xff, 0x12}, 512<<10)
	server := make(chan error, 1)
	go func() {
		data, e := io.ReadAll(s.backend)
		if e == nil && !bytes.Equal(data, payload) {
			e = fmt.Errorf("payload mismatch: %d", len(data))
		}
		if e == nil {
			_, e = s.backend.Write(response)
		}
		s.backend.Close()
		server <- e
	}()
	if _, e := s.input.Write(payload); e != nil {
		t.Fatal(e)
	}
	s.input.Close()
	finishPump(t, s, true)
	if e := <-server; e != nil {
		t.Fatal(e)
	}
	if !bytes.Equal(s.output.Bytes(), response) {
		t.Fatal("binary response mismatch")
	}
	if s.err.Len() != 0 {
		t.Fatal(s.err.String())
	}
}
func TestRevocationAndSupervisorLoss(t *testing.T) {
	for _, which := range []string{"lease", "lifeline"} {
		t.Run(which, func(t *testing.T) {
			s := startPump(t, "duplex")
			go func() { _, _ = s.input.Write(bytes.Repeat([]byte{0xa7}, 8<<20)); s.input.Close() }()
			time.Sleep(100 * time.Millisecond)
			start := time.Now()
			if which == "lease" {
				os.WriteFile(s.lease, []byte("REVOKED"), 0600)
			} else {
				s.life.Close()
			}
			finishPump(t, s, false)
			if time.Since(start) > time.Second {
				t.Fatal("revocation was not prompt")
			}
			if s.output.Len() != 0 {
				t.Fatal("errors leaked into binary output")
			}
		})
	}
}
func TestBandwidthLimits(t *testing.T) {
	for _, n := range []int{33554432, 33554431, 33554433} {
		t.Run(fmt.Sprint(n), func(t *testing.T) {
			s := startPump(t, "benchmark")
			go func() { _, _ = io.CopyN(s.input, strings.NewReader(strings.Repeat("z", n)), int64(n)); s.input.Close() }()
			finishPump(t, s, n == 33554432)
			if n == 33554432 && s.output.String() != "{\"bytes\":33554432}\n" {
				t.Fatal("bandwidth result changed")
			}
			if n != 33554432 && s.output.Len() != 0 {
				t.Fatal("invalid benchmark emitted a result")
			}
		})
	}
}
