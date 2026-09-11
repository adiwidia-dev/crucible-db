package proxy

import (
	"errors"
	"net"
	"testing"
	"time"
)

func TestIdleConnectionEnforcesReadTimeout(t *testing.T) {
	server, client := net.Pipe()
	defer client.Close()
	connection := &idleConnection{Conn: server, timeout: 20 * time.Millisecond, listener: &idleListener{connections: map[net.Conn]struct{}{server: {}}}}
	defer connection.Close()

	buffer := make([]byte, 1)
	_, err := connection.Read(buffer)
	if err == nil {
		t.Fatal("expected idle read to time out")
	}
	var networkError net.Error
	if !errors.As(err, &networkError) || !networkError.Timeout() {
		t.Fatalf("expected timeout error, got %v", err)
	}
}
