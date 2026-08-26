package tunnel_test

import (
	"errors"
	"testing"

	"github.com/adiwidia-dev/crucible-db/native/internal/tunnel"
)

func TestNegotiateRejectsUnsupportedMajorVersion(t *testing.T) {
	_, err := tunnel.Negotiate(tunnel.Hello{Protocol: "crucible.tunnel.v2"})
	if !errors.Is(err, tunnel.ErrUnsupportedVersion) {
		t.Fatalf("expected unsupported version, got %v", err)
	}
}

func TestNegotiateAcceptsCurrentProtocol(t *testing.T) {
	protocol, err := tunnel.Negotiate(tunnel.Hello{Protocol: tunnel.CurrentProtocol})
	if err != nil {
		t.Fatalf("negotiate current protocol: %v", err)
	}

	if protocol != tunnel.CurrentProtocol {
		t.Fatalf("expected protocol %q, got %q", tunnel.CurrentProtocol, protocol)
	}
}
