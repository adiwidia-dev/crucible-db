package proxy

import "sync/atomic"

type Health struct {
	ready atomic.Bool
}

func (health *Health) SetReady(ready bool) {
	health.ready.Store(ready)
}

func (health *Health) Ready() bool {
	return health.ready.Load()
}
