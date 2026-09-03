package proxy_test

import (
	"sync/atomic"
	"testing"

	"github.com/adiwidia-dev/crucible-db/native/internal/proxy"
)

func TestRevocationClosesOnlyConnectionsForTheAffectedLease(t *testing.T) {
	registry, err := proxy.NewConnectionRegistry(proxy.Limits{Global: 3, Lease: 3, User: 3})
	if err != nil {
		t.Fatal(err)
	}
	for _, identity := range []proxy.ConnectionIdentity{
		{ID: "connection-a", LeaseID: "lease-a", UserID: "user-a"},
		{ID: "connection-b", LeaseID: "lease-b", UserID: "user-b"},
	} {
		if err := registry.Reserve(identity); err != nil {
			t.Fatal(err)
		}
	}
	var closedA atomic.Int32
	var closedB atomic.Int32
	registry.SetCloser("connection-a", func() { closedA.Add(1) })
	registry.SetCloser("connection-b", func() { closedB.Add(1) })

	registry.RevokeLease("lease-a")

	if closedA.Load() != 1 {
		t.Fatalf("expected lease-a connection to close once, got %d", closedA.Load())
	}
	if closedB.Load() != 0 {
		t.Fatalf("expected unrelated lease to remain open, got %d closes", closedB.Load())
	}
	registry.RevokeLease("lease-a")
	if closedA.Load() != 2 {
		t.Fatalf("expected revocation to remain safe to repeat, got %d closes", closedA.Load())
	}
}
