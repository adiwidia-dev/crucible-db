package proxy

import (
	"errors"
	"sync"
)

var ErrConnectionLimitReached = errors.New("native proxy connection limit reached")

type ConnectionRegistry struct {
	mutex          sync.Mutex
	connections    map[string]ConnectionIdentity
	closers        map[string]func()
	byLease        map[string]int
	byUser         map[string]int
	limits         Limits
	onCountChanged func(int)
}

func (registry *ConnectionRegistry) SetCountObserver(observer func(int)) {
	registry.mutex.Lock()
	registry.onCountChanged = observer
	count := len(registry.connections)
	registry.mutex.Unlock()
	if observer != nil {
		observer(count)
	}
}

type Limits struct {
	Global int
	Lease  int
	User   int
}

type ConnectionIdentity struct {
	ID      string
	LeaseID string
	UserID  string
}

func NewConnectionRegistry(limits Limits) (*ConnectionRegistry, error) {
	if limits.Global < 1 || limits.Lease < 1 || limits.User < 1 {
		return nil, errors.New("native proxy connection limits must be positive")
	}

	return &ConnectionRegistry{
		connections: make(map[string]ConnectionIdentity),
		closers:     make(map[string]func()),
		byLease:     make(map[string]int),
		byUser:      make(map[string]int),
		limits:      limits,
	}, nil
}

func (registry *ConnectionRegistry) SetCloser(connectionID string, closer func()) {
	registry.mutex.Lock()
	defer registry.mutex.Unlock()
	if _, exists := registry.connections[connectionID]; exists {
		registry.closers[connectionID] = closer
	}
}

func (registry *ConnectionRegistry) RevokeLease(leaseID string) {
	registry.mutex.Lock()
	closers := make([]func(), 0)
	for connectionID, identity := range registry.connections {
		if identity.LeaseID == leaseID && registry.closers[connectionID] != nil {
			closers = append(closers, registry.closers[connectionID])
		}
	}
	registry.mutex.Unlock()

	for _, closer := range closers {
		closer()
	}
}

func (registry *ConnectionRegistry) Reserve(identity ConnectionIdentity) error {
	if identity.ID == "" || identity.LeaseID == "" || identity.UserID == "" {
		return errors.New("native proxy connection identity is incomplete")
	}
	registry.mutex.Lock()
	defer registry.mutex.Unlock()
	if _, exists := registry.connections[identity.ID]; exists {
		return nil
	}
	if len(registry.connections) >= registry.limits.Global || registry.byLease[identity.LeaseID] >= registry.limits.Lease || registry.byUser[identity.UserID] >= registry.limits.User {
		return ErrConnectionLimitReached
	}
	registry.connections[identity.ID] = identity
	registry.byLease[identity.LeaseID]++
	registry.byUser[identity.UserID]++
	if registry.onCountChanged != nil {
		registry.onCountChanged(len(registry.connections))
	}

	return nil
}

func (registry *ConnectionRegistry) Release(connectionID string) {
	registry.mutex.Lock()
	defer registry.mutex.Unlock()
	identity, exists := registry.connections[connectionID]
	if !exists {
		return
	}
	delete(registry.connections, connectionID)
	delete(registry.closers, connectionID)
	registry.byLease[identity.LeaseID]--
	registry.byUser[identity.UserID]--
	if registry.byLease[identity.LeaseID] == 0 {
		delete(registry.byLease, identity.LeaseID)
	}
	if registry.byUser[identity.UserID] == 0 {
		delete(registry.byUser, identity.UserID)
	}
	if registry.onCountChanged != nil {
		registry.onCountChanged(len(registry.connections))
	}
}

func (registry *ConnectionRegistry) Count() int {
	registry.mutex.Lock()
	defer registry.mutex.Unlock()

	return len(registry.connections)
}
