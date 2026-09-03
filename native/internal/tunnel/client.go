package tunnel

import (
	"context"
	"errors"
	"net"
	"net/http"
	"net/url"

	"github.com/coder/websocket"
)

type ClientConnection struct {
	LeaseID          string
	DeviceID         string
	ConnectionID     string
	BearerToken      string
	DatabaseProtocol string
}

func Dial(ctx context.Context, rawURL string, connection ClientConnection) (*websocket.Conn, error) {
	websocketEndpoint, err := websocketURL(rawURL)
	if err != nil {
		return nil, err
	}
	if connection.LeaseID == "" || connection.DeviceID == "" || connection.ConnectionID == "" || connection.BearerToken == "" || (connection.DatabaseProtocol != "postgresql" && connection.DatabaseProtocol != "mysql") {
		return nil, errors.New("native tunnel credentials are incomplete")
	}

	headers := http.Header{}
	headers.Set("Authorization", "Bearer "+connection.BearerToken)
	headers.Set("X-Crucible-Device-Id", connection.DeviceID)
	headers.Set("X-Crucible-Connection-Id", connection.ConnectionID)
	headers.Set("X-Crucible-Tunnel-Protocol", CurrentProtocol)
	headers.Set("X-Crucible-Database-Protocol", connection.DatabaseProtocol)
	websocketConnection, _, err := websocket.Dial(ctx, websocketEndpoint, &websocket.DialOptions{
		HTTPHeader:      headers,
		Subprotocols:    []string{CurrentProtocol},
		CompressionMode: websocket.CompressionDisabled,
	})
	if err != nil {
		return nil, err
	}
	if websocketConnection.Subprotocol() != CurrentProtocol {
		websocketConnection.CloseNow()

		return nil, ErrUnsupportedVersion
	}

	return websocketConnection, nil
}

func websocketURL(rawURL string) (string, error) {
	endpoint, err := url.Parse(rawURL)
	if err != nil || endpoint.Host == "" {
		return "", errors.New("invalid native tunnel URL")
	}

	switch endpoint.Scheme {
	case "https":
		endpoint.Scheme = "wss"
	case "http":
		endpoint.Scheme = "ws"
	case "wss", "ws":
	default:
		return "", errors.New("invalid native tunnel URL")
	}

	return endpoint.String(), nil
}

func ListenLoopback(address string) (net.Listener, error) {
	host, _, err := net.SplitHostPort(address)
	if err != nil {
		return nil, err
	}
	ip := net.ParseIP(host)
	if ip == nil || !ip.IsLoopback() {
		return nil, ErrNonLoopbackAddress
	}

	return net.Listen("tcp", address)
}

type Opener func(context.Context, net.Conn) (*websocket.Conn, error)

// ServeLoopback accepts local database-client connections and gives each one
// an independent tunnel. It only exits with an error when accepting stops for
// a reason other than context cancellation or listener shutdown.
func ServeLoopback(ctx context.Context, listener net.Listener, open Opener) error {
	if listener == nil || open == nil {
		return errors.New("native tunnel listener is not configured")
	}
	go func() {
		<-ctx.Done()
		_ = listener.Close()
	}()

	for {
		connection, err := listener.Accept()
		if err != nil {
			if errors.Is(err, net.ErrClosed) || ctx.Err() != nil {
				return nil
			}

			return err
		}
		go func() {
			websocketConnection, err := open(ctx, connection)
			if err != nil {
				_ = connection.Close()

				return
			}
			_ = Bridge(ctx, connection, websocketConnection)
		}()
	}
}
