package postgres

import (
	"context"
	"sync"
	"testing"
	"time"

	"github.com/jackc/pgx/v5/pgproto3"
)

func TestServerInitializesOneCancellationRegistryUnderConcurrentSessionStartup(t *testing.T) {
	server := &Server{}
	registries := make(chan *cancellationRegistry, 64)
	var waitGroup sync.WaitGroup
	for range 64 {
		waitGroup.Add(1)
		go func() {
			defer waitGroup.Done()
			registries <- server.cancellationRegistry()
		}()
	}
	waitGroup.Wait()
	close(registries)

	var first *cancellationRegistry
	for registry := range registries {
		if first == nil {
			first = registry
			continue
		}
		if registry != first {
			t.Fatal("expected every session to share one cancellation registry")
		}
	}
}

func TestCancellationRegistryCancelsOnlyMatchingVariableLengthKey(t *testing.T) {
	registry := newCancellationRegistry()
	sessionContext, cancel := context.WithCancel(context.Background())
	key, unregister, err := registry.register(nil, cancel, cancellationSecretLength(pgproto3.ProtocolVersion32))
	if err != nil {
		t.Fatal(err)
	}
	defer unregister()

	registry.cancel(context.Background(), key.processID, []byte("wrong"))
	select {
	case <-sessionContext.Done():
		t.Fatal("wrong cancellation key must be silent")
	default:
	}

	registry.cancel(context.Background(), key.processID, key.secret)
	select {
	case <-sessionContext.Done():
	case <-time.After(time.Second):
		t.Fatal("matching cancellation key did not cancel the active query")
	}
}

func TestCancellationRegistryRemovesClosedSessions(t *testing.T) {
	registry := newCancellationRegistry()
	sessionContext, cancel := context.WithCancel(context.Background())
	key, unregister, err := registry.register(nil, cancel, cancellationSecretLength(pgproto3.ProtocolVersion32))
	if err != nil {
		t.Fatal(err)
	}
	unregister()

	registry.cancel(context.Background(), key.processID, key.secret)
	select {
	case <-sessionContext.Done():
		t.Fatal("closed session must not be cancellable")
	default:
	}
}

func TestCancellationRegistryOnlyCancelsActiveQueries(t *testing.T) {
	registry := newCancellationRegistry()
	key, unregister, err := registry.register(nil, nil, cancellationSecretLength(pgproto3.ProtocolVersion32))
	if err != nil {
		t.Fatal(err)
	}
	defer unregister()
	queryContext, cancel := context.WithCancel(context.Background())
	end := registry.begin(key, cancel)
	registry.cancel(context.Background(), key.processID, key.secret)
	select {
	case <-queryContext.Done():
	case <-time.After(time.Second):
		t.Fatal("active query was not cancelled")
	}
	end()
}

func TestCancellationSecretLengthMatchesFrontendProtocolVersion(t *testing.T) {
	tests := []struct {
		name            string
		protocolVersion uint32
		expected        int
	}{
		{name: "protocol 3.0", protocolVersion: pgproto3.ProtocolVersion30, expected: legacyCancellationSecretLength},
		{name: "protocol 3.2", protocolVersion: pgproto3.ProtocolVersion32, expected: extendedCancellationSecretLength},
	}

	for _, test := range tests {
		t.Run(test.name, func(t *testing.T) {
			if actual := cancellationSecretLength(test.protocolVersion); actual != test.expected {
				t.Fatalf("expected cancellation secret length %d, got %d", test.expected, actual)
			}
		})
	}
}

func TestCancellationRegistryIssuesLegacyProtocolSecret(t *testing.T) {
	registry := newCancellationRegistry()
	key, unregister, err := registry.register(nil, nil, cancellationSecretLength(pgproto3.ProtocolVersion30))
	if err != nil {
		t.Fatal(err)
	}
	defer unregister()

	if len(key.secret) != legacyCancellationSecretLength {
		t.Fatalf("expected legacy cancellation secret length %d, got %d", legacyCancellationSecretLength, len(key.secret))
	}
}
