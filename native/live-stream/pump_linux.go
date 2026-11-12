// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Richard Skinner
package main

import (
	"errors"
	"fmt"
	"syscall"
	"time"
)

const bufferSize = 1 << 20
const batchSize = 256 << 10
const transferWindow = 1920 * time.Second

type queue struct {
	data        []byte
	start, size int
}

func newQueue() *queue { return &queue{data: make([]byte, bufferSize)} }
func (q *queue) room() []byte {
	end := (q.start + q.size) % len(q.data)
	n := min(len(q.data)-q.size, len(q.data)-end, batchSize)
	return q.data[end : end+n]
}
func (q *queue) bytes() []byte {
	return q.data[q.start : q.start+min(q.size, len(q.data)-q.start, batchSize)]
}
func (q *queue) consumed(n int)             { q.start = (q.start + n) % len(q.data); q.size -= n }
func fdSet(set *syscall.FdSet, fd int)      { set.Bits[fd/64] |= 1 << uint(fd%64) }
func fdHas(set *syscall.FdSet, fd int) bool { return set.Bits[fd/64]&(1<<uint(fd%64)) != 0 }
func wouldBlock(e error) bool {
	return e == syscall.EAGAIN || e == syscall.EWOULDBLOCK || e == syscall.EINTR
}
func streamFD(fd int) bool {
	var s syscall.Stat_t
	if syscall.Fstat(fd, &s) != nil {
		return false
	}
	t := s.Mode & syscall.S_IFMT
	return t == syscall.S_IFIFO || t == syscall.S_IFSOCK
}
func waitIO(r, w *syscall.FdSet) error {
	tv := syscall.Timeval{Usec: 250000}
	_, e := syscall.Select(7, r, w, nil, &tv)
	return e
}
func telemetry(n int64) { _, _ = syscall.Write(6, []byte(fmt.Sprintf("%d\n", n))) }
func runStream(mode string, alive func() error) error {
	for _, fd := range []int{0, 1, 5, 6} {
		if !streamFD(fd) {
			return errors.New("missing supervised stream descriptor")
		}
		if e := syscall.SetNonblock(fd, true); e != nil {
			return e
		}
	}
	if mode == "duplex" {
		for _, fd := range []int{3, 4} {
			if !streamFD(fd) {
				return errors.New("invalid backend descriptor")
			}
			if e := syscall.SetNonblock(fd, true); e != nil {
				return e
			}
		}
	}
	if e := alive(); e != nil {
		return e
	}
	start := time.Now()
	lastReport := start
	forward, reverse := newQueue(), newQueue()
	inputOpen, backendOpen, halfClosed := true, true, false
	var received int64
	finish := []byte(nil)
	for {
		window := transferWindow
		if mode == "benchmark" {
			window = 30 * time.Second
		}
		if time.Since(start) > window {
			return errors.New("bounded transport lifetime expired")
		}
		var r, w syscall.FdSet
		fdSet(&r, 5)
		if inputOpen && forward.size < bufferSize {
			fdSet(&r, 0)
		}
		if mode == "duplex" {
			if backendOpen && reverse.size < bufferSize {
				fdSet(&r, 3)
			}
			if forward.size > 0 {
				fdSet(&w, 4)
			}
			if reverse.size > 0 {
				fdSet(&w, 1)
			}
		} else if len(finish) > 0 {
			fdSet(&w, 1)
		}
		waitErr := waitIO(&r, &w)
		if waitErr != nil && !wouldBlock(waitErr) {
			return waitErr
		}
		// Check AFTER waiting, before any queued/new bytes can be delivered. No
		// authority/metadata cache. Idle streams are revoked within one poll period.
		if e := alive(); e != nil {
			return e
		}
		if waitErr != nil {
			continue
		}
		if fdHas(&r, 5) {
			return errors.New("transport supervisor disconnected")
		}
		if fdHas(&r, 0) && inputOpen {
			for batch := 0; batch < batchSize && forward.size < bufferSize; {
				space := forward.room()
				if len(space) > batchSize-batch {
					space = space[:batchSize-batch]
				}
				n, err := syscall.Read(0, space)
				if n > 0 {
					forward.size += n
					batch += n
					received += int64(n)
					if mode == "benchmark" {
						forward.consumed(n)
						if received > 33554432 {
							return errors.New("oversized bandwidth sample")
						}
					}
				}
				if err != nil {
					if wouldBlock(err) {
						break
					}
					return err
				}
				if n == 0 {
					inputOpen = false
					break
				}
			}
		}
		if mode == "duplex" {
			if fdHas(&r, 3) && backendOpen {
				for batch := 0; batch < batchSize && reverse.size < bufferSize; {
					space := reverse.room()
					if len(space) > batchSize-batch {
						space = space[:batchSize-batch]
					}
					n, err := syscall.Read(3, space)
					if n > 0 {
						reverse.size += n
						batch += n
					}
					if err != nil {
						if wouldBlock(err) {
							break
						}
						return err
					}
					if n == 0 {
						backendOpen = false
						break
					}
				}
			}
			for _, item := range []struct {
				fd int
				q  *queue
			}{{4, forward}, {1, reverse}} {
				if fdHas(&w, item.fd) && item.q.size > 0 {
					n, err := syscall.Write(item.fd, item.q.bytes())
					if n > 0 {
						item.q.consumed(n)
					}
					if err != nil && !wouldBlock(err) {
						return err
					}
				}
			}
			if !inputOpen && forward.size == 0 && !halfClosed {
				_ = syscall.Shutdown(4, syscall.SHUT_WR)
				_ = syscall.Close(4)
				halfClosed = true
			}
			if !backendOpen {
				if forward.size != 0 {
					return errors.New("backend closed with undelivered bytes")
				}
				if reverse.size == 0 {
					telemetry(received)
					return nil
				}
			}
		} else {
			if !inputOpen && finish == nil {
				if received != 33554432 {
					return errors.New("incomplete bandwidth sample")
				}
				finish = []byte("{\"bytes\":33554432}\n")
			}
			if fdHas(&w, 1) && len(finish) > 0 {
				n, err := syscall.Write(1, finish)
				if n > 0 {
					finish = finish[n:]
					if len(finish) == 0 {
						telemetry(received)
						return nil
					}
				}
				if err != nil && !wouldBlock(err) {
					return err
				}
			}
		}
		if time.Since(lastReport) >= time.Second {
			telemetry(received)
			lastReport = time.Now()
		}
	}
}
