package tunnel_test

import (
	"context"
	"net"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"github.com/adiwidia-dev/crucible-db/native/internal/tunnel"
	"github.com/coder/websocket"
)

func TestTunnelServerAuthorizesBeforeWebSocketUpgrade(t *testing.T) {
	server := tunnel.Server{Authorize: func(context.Context, tunnel.AuthorizationRequest) (tunnel.AuthorizationResult, error) {
		t.Fatal("authorizer should not receive incomplete client credentials")

		return tunnel.AuthorizationResult{}, nil
	}}
	response := httptest.NewRecorder()
	request := httptest.NewRequest(http.MethodGet, "/native-tunnel/v1/tunnel/lease-1", nil)

	server.Handler().ServeHTTP(response, request)

	if response.Code != http.StatusUnauthorized {
		t.Fatalf("unexpected status: %d", response.Code)
	}
}

func TestTunnelServerForwardsBinaryFramesOnly(t *testing.T) {
	proxySide, databaseSide := net.Pipe()
	server := httptest.NewServer(tunnel.Server{
		Authorize: func(_ context.Context, request tunnel.AuthorizationRequest) (tunnel.AuthorizationResult, error) {
			if request.BearerToken != "bearer-token" || request.Protocol != tunnel.CurrentProtocol || request.DatabaseProtocol != "postgresql" {
				t.Fatalf("unexpected authorization request: %#v", request)
			}

			return tunnel.AuthorizationResult{UpstreamAddress: "database"}, nil
		},
		Dial: func(_ context.Context, address string) (net.Conn, error) {
			if address != "database" {
				t.Fatalf("unexpected upstream address: %s", address)
			}

			return proxySide, nil
		},
	}.Handler())
	defer server.Close()
	defer databaseSide.Close()

	websocketURL := "ws" + strings.TrimPrefix(server.URL, "http") + "/native-tunnel/v1/tunnel/lease-1"
	headers := http.Header{}
	headers.Set("Authorization", "Bearer bearer-token")
	headers.Set("X-Crucible-Device-Id", "device-1")
	headers.Set("X-Crucible-Connection-Id", "connection-1")
	headers.Set("X-Crucible-Tunnel-Protocol", tunnel.CurrentProtocol)
	headers.Set("X-Crucible-Database-Protocol", "postgresql")
	connection, _, err := websocket.Dial(context.Background(), websocketURL, &websocket.DialOptions{HTTPHeader: headers, Subprotocols: []string{tunnel.CurrentProtocol}, CompressionMode: websocket.CompressionDisabled})
	if err != nil {
		t.Fatal(err)
	}
	defer connection.CloseNow()

	if err := connection.Write(context.Background(), websocket.MessageBinary, []byte("SELECT 1")); err != nil {
		t.Fatal(err)
	}
	databaseSide.SetReadDeadline(time.Now().Add(time.Second))
	buffer := make([]byte, len("SELECT 1"))
	if _, err := databaseSide.Read(buffer); err != nil {
		t.Fatal(err)
	}
	if string(buffer) != "SELECT 1" {
		t.Fatalf("unexpected upstream request: %q", buffer)
	}

	if _, err := databaseSide.Write([]byte("result")); err != nil {
		t.Fatal(err)
	}
	_, payload, err := connection.Read(context.Background())
	if err != nil {
		t.Fatal(err)
	}
	if string(payload) != "result" {
		t.Fatalf("unexpected tunnel response: %q", payload)
	}
}

func TestTunnelServerRequiresTheCurrentWebSocketSubprotocolBeforeAuthorization(t *testing.T) {
	authorized := false
	server := tunnel.Server{Authorize: func(context.Context, tunnel.AuthorizationRequest) (tunnel.AuthorizationResult, error) {
		authorized = true

		return tunnel.AuthorizationResult{}, nil
	}}
	response := httptest.NewRecorder()
	request := httptest.NewRequest(http.MethodGet, "/native-tunnel/v1/tunnel/lease-1", nil)
	request.Header.Set("Authorization", "Bearer bearer-token")
	request.Header.Set("X-Crucible-Device-Id", "device-1")
	request.Header.Set("X-Crucible-Connection-Id", "connection-1")
	request.Header.Set("X-Crucible-Tunnel-Protocol", tunnel.CurrentProtocol)
	request.Header.Set("X-Crucible-Database-Protocol", "postgresql")

	server.Handler().ServeHTTP(response, request)

	if response.Code != http.StatusUpgradeRequired || authorized {
		t.Fatalf("expected an unsupported protocol rejection before authorization, got status=%d authorized=%t", response.Code, authorized)
	}
}
