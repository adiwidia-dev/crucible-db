package tunnel

import "testing"

func TestWebSocketURLMapsSameOriginHTTPTransportSchemes(t *testing.T) {
	tests := map[string]string{
		"https://crucible.example.test/native-tunnel/v1/tunnel/lease-1": "wss://crucible.example.test/native-tunnel/v1/tunnel/lease-1",
		"http://127.0.0.1:8000/native-tunnel/v1/tunnel/lease-1":         "ws://127.0.0.1:8000/native-tunnel/v1/tunnel/lease-1",
		"wss://crucible.example.test/native-tunnel/v1/tunnel/lease-1":   "wss://crucible.example.test/native-tunnel/v1/tunnel/lease-1",
		"ws://127.0.0.1:8000/native-tunnel/v1/tunnel/lease-1":           "ws://127.0.0.1:8000/native-tunnel/v1/tunnel/lease-1",
	}

	for input, expected := range tests {
		actual, err := websocketURL(input)
		if err != nil {
			t.Fatalf("normalize %q: %v", input, err)
		}
		if actual != expected {
			t.Fatalf("normalize %q: expected %q, got %q", input, expected, actual)
		}
	}
}

func TestWebSocketURLRejectsUnsupportedSchemes(t *testing.T) {
	if _, err := websocketURL("ftp://crucible.example.test/tunnel"); err == nil {
		t.Fatal("expected unsupported scheme to be rejected")
	}
}
