package load

import (
	"fmt"
	"sync"
	"sync/atomic"
	"testing"

	"github.com/adiwidia-dev/crucible-db/native/internal/proxy"
)

func TestConnectionRegistryEnforcesCeilingsUnderConcurrentLoad(t *testing.T) {
	registry, err := proxy.NewConnectionRegistry(proxy.Limits{Global: 100, Lease: 4, User: 10})
	if err != nil {
		t.Fatalf("create connection registry: %v", err)
	}

	var accepted atomic.Int32
	var rejected atomic.Int32
	var waitGroup sync.WaitGroup
	for index := range 500 {
		waitGroup.Add(1)
		go func(index int) {
			defer waitGroup.Done()
			err := registry.Reserve(proxy.ConnectionIdentity{
				ID:      fmt.Sprintf("connection-%d", index),
				LeaseID: fmt.Sprintf("lease-%d", index%40),
				UserID:  fmt.Sprintf("user-%d", index%20),
			})
			if err == nil {
				accepted.Add(1)
				return
			}
			if err == proxy.ErrConnectionLimitReached {
				rejected.Add(1)
				return
			}
			t.Errorf("reserve connection: %v", err)
		}(index)
	}
	waitGroup.Wait()

	if accepted.Load() == 0 || accepted.Load() > 100 || registry.Count() != int(accepted.Load()) {
		t.Fatalf("registry count is outside its global ceiling: accepted=%d count=%d", accepted.Load(), registry.Count())
	}
	if rejected.Load() == 0 {
		t.Fatal("expected concurrent load to encounter a configured connection ceiling")
	}
}
