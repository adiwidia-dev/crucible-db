package tunnel

import "testing"

func FuzzNegotiate(f *testing.F) {
	f.Add(CurrentProtocol)
	f.Add("crucible.tunnel.v0")
	f.Add("")

	f.Fuzz(func(t *testing.T, protocol string) {
		_, _ = Negotiate(Hello{Protocol: protocol})
	})
}
