package tunnel_test

import (
	"context"
	"io"
	"net"
	"testing"
	"time"

	"github.com/adiwidia-dev/crucible-db/native/internal/tunnel"
	"github.com/coder/websocket"
)

func TestListenLoopbackRejectsNonLoopbackAddresses(t *testing.T) {
	if _, err := tunnel.ListenLoopback("0.0.0.0:0"); err != tunnel.ErrNonLoopbackAddress {
		t.Fatalf("expected loopback rejection, got %v", err)
	}
	listener, err := tunnel.ListenLoopback("127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer listener.Close()
}

func TestWriteAllHandlesShortWrites(t *testing.T) {
	left, right := net.Pipe()
	defer left.Close()
	defer right.Close()
	done := make(chan error, 1)
	go func() {
		buffer := make([]byte, 3)
		_, err := io.ReadFull(right, buffer)
		done <- err
	}()
	if _, err := left.Write([]byte("abc")); err != nil {
		t.Fatal(err)
	}
	select {
	case err := <-done:
		if err != nil {
			t.Fatal(err)
		}
	case <-time.After(time.Second):
		t.Fatal("pipe write did not complete")
	}
}

func TestServeLoopbackStopsWhenItsContextIsCancelled(t *testing.T) {
	listener, err := tunnel.ListenLoopback("127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	tunnelContext, cancel := context.WithCancel(context.Background())
	cancel()
	if err := tunnel.ServeLoopback(tunnelContext, listener, func(context.Context, net.Conn) (*websocket.Conn, error) {
		t.Fatal("opener should not run")

		return nil, nil
	}); err != nil {
		t.Fatal(err)
	}
}
