package postgres

import (
	"context"
	"crypto/rand"
	"crypto/subtle"
	"fmt"
	"sync"
	"time"

	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgproto3"
)

const (
	legacyCancellationSecretLength   = 4
	extendedCancellationSecretLength = 32
	maxCancellationSecretLength      = 256
)

type cancellationKey struct {
	processID uint32
	secret    []byte
}

type cancellationTarget struct {
	upstream *pgx.Conn
	cancel   context.CancelFunc
	secret   []byte
}

type cancellationRegistry struct {
	mu      sync.RWMutex
	nextID  uint32
	targets map[uint32]cancellationTarget
}

func newCancellationRegistry() *cancellationRegistry {
	return &cancellationRegistry{nextID: 1, targets: make(map[uint32]cancellationTarget)}
}

func cancellationSecretLength(protocolVersion uint32) int {
	if protocolVersion >= pgproto3.ProtocolVersion32 {
		return extendedCancellationSecretLength
	}

	return legacyCancellationSecretLength
}

func (registry *cancellationRegistry) register(upstream *pgx.Conn, cancel context.CancelFunc, secretLength int) (cancellationKey, func(), error) {
	if secretLength < legacyCancellationSecretLength || secretLength > maxCancellationSecretLength {
		return cancellationKey{}, nil, fmt.Errorf("invalid cancellation secret length: %d", secretLength)
	}

	secret := make([]byte, secretLength)
	if _, err := rand.Read(secret); err != nil {
		return cancellationKey{}, nil, err
	}

	registry.mu.Lock()
	processID := registry.nextID
	registry.nextID++
	if registry.nextID == 0 {
		registry.nextID = 1
	}
	registry.targets[processID] = cancellationTarget{upstream: upstream, cancel: cancel, secret: secret}
	registry.mu.Unlock()

	return cancellationKey{processID: processID, secret: secret}, func() {
		registry.mu.Lock()
		delete(registry.targets, processID)
		registry.mu.Unlock()
	}, nil
}

func (registry *cancellationRegistry) begin(key cancellationKey, cancel context.CancelFunc) func() {
	registry.mu.Lock()
	target, found := registry.targets[key.processID]
	if found && subtle.ConstantTimeCompare(target.secret, key.secret) == 1 {
		target.cancel = cancel
		registry.targets[key.processID] = target
	}
	registry.mu.Unlock()

	return func() {
		registry.mu.Lock()
		target, found := registry.targets[key.processID]
		if found && subtle.ConstantTimeCompare(target.secret, key.secret) == 1 {
			target.cancel = nil
			registry.targets[key.processID] = target
		}
		registry.mu.Unlock()
	}
}

func (registry *cancellationRegistry) cancel(ctx context.Context, processID uint32, secret []byte) {
	registry.mu.RLock()
	target, found := registry.targets[processID]
	registry.mu.RUnlock()
	if !found {
		return
	}

	// PostgreSQL 18 allows variable-length cancellation secrets. Wrong keys are
	// intentionally silent so clients cannot use cancellation as an oracle.
	if subtle.ConstantTimeCompare(target.secret, secret) != 1 {
		return
	}

	if target.upstream != nil {
		cancelContext, cancel := context.WithTimeout(ctx, 2*time.Second)
		defer cancel()
		_ = target.upstream.PgConn().CancelRequest(cancelContext)

		return
	}

	if target.cancel != nil {
		target.cancel()
	}
}
