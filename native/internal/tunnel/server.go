package tunnel

import (
	"bufio"
	"context"
	"encoding/json"
	"errors"
	"net"
	"net/http"
	"strings"
	"time"

	"github.com/coder/websocket"
)

type AuthorizationRequest struct {
	LeaseID          string
	DeviceID         string
	ConnectionID     string
	Protocol         string
	DatabaseProtocol string
	BearerToken      string
	RemoteAddr       string
}

type AuthorizationResult struct {
	UpstreamAddress              string
	AuthAttemptID                string
	ProtocolAuthenticationSecret string
}

type Authorizer func(context.Context, AuthorizationRequest) (AuthorizationResult, error)

type Server struct {
	Authorize     Authorizer
	Dial          func(context.Context, string) (net.Conn, error)
	ReportTraffic func(context.Context, string, Traffic) error
}

func (server Server) Handler() http.Handler {
	return http.HandlerFunc(server.handle)
}

func (server Server) handle(writer http.ResponseWriter, request *http.Request) {
	if request.Method != http.MethodGet {
		writer.WriteHeader(http.StatusMethodNotAllowed)

		return
	}
	if server.Authorize == nil {
		writer.WriteHeader(http.StatusServiceUnavailable)

		return
	}
	leaseID := strings.TrimPrefix(request.URL.Path, "/native-tunnel/v1/tunnel/")
	bearerToken := strings.TrimPrefix(request.Header.Get("Authorization"), "Bearer ")
	databaseProtocol := request.Header.Get("X-Crucible-Database-Protocol")
	if leaseID == "" || bearerToken == "" || request.Header.Get("X-Crucible-Device-Id") == "" || request.Header.Get("X-Crucible-Connection-Id") == "" || request.Header.Get("X-Crucible-Tunnel-Protocol") != CurrentProtocol || (databaseProtocol != "postgresql" && databaseProtocol != "mysql") {
		writer.WriteHeader(http.StatusUnauthorized)

		return
	}
	if !offersSubprotocol(request.Header.Get("Sec-WebSocket-Protocol"), CurrentProtocol) {
		writer.Header().Set("Sec-WebSocket-Protocol", CurrentProtocol)
		writer.WriteHeader(http.StatusUpgradeRequired)

		return
	}

	authorizationRequest := AuthorizationRequest{
		LeaseID:          leaseID,
		DeviceID:         request.Header.Get("X-Crucible-Device-Id"),
		ConnectionID:     request.Header.Get("X-Crucible-Connection-Id"),
		Protocol:         request.Header.Get("X-Crucible-Tunnel-Protocol"),
		DatabaseProtocol: databaseProtocol,
		BearerToken:      bearerToken,
		RemoteAddr:       request.RemoteAddr,
	}
	authorization, err := server.Authorize(request.Context(), authorizationRequest)
	if err != nil || authorization.UpstreamAddress == "" {
		writer.WriteHeader(http.StatusUnauthorized)

		return
	}
	websocketConnection, err := websocket.Accept(writer, request, &websocket.AcceptOptions{
		CompressionMode: websocket.CompressionDisabled,
		Subprotocols:    []string{CurrentProtocol},
	})
	if err != nil {
		return
	}
	defer websocketConnection.CloseNow()
	if websocketConnection.Subprotocol() != CurrentProtocol {
		_ = websocketConnection.Close(CloseCodeUnsupportedVersion, "unsupported tunnel protocol")

		return
	}

	dial := server.Dial
	if dial == nil {
		dialer := net.Dialer{Timeout: 10 * time.Second}
		dial = func(ctx context.Context, address string) (net.Conn, error) {
			return dialer.DialContext(ctx, "tcp", address)
		}
	}
	upstream, err := dial(request.Context(), authorization.UpstreamAddress)
	if err != nil {
		_ = websocketConnection.Close(CloseCodeInternalError, "upstream unavailable")

		return
	}
	defer upstream.Close()
	if authorization.AuthAttemptID != "" {
		if err := WriteConnectionMetadata(upstream, ConnectionMetadata{
			AuthAttemptID:                authorization.AuthAttemptID,
			Protocol:                     authorizationRequest.DatabaseProtocol,
			ProxyConnectionID:            authorizationRequest.ConnectionID,
			ProtocolAuthenticationSecret: authorization.ProtocolAuthenticationSecret,
		}); err != nil {
			return
		}
	}

	traffic, _ := BridgeWithTraffic(request.Context(), upstream, websocketConnection)
	if server.ReportTraffic != nil && authorization.AuthAttemptID != "" {
		reportContext, cancel := context.WithTimeout(context.Background(), 2*time.Second)
		defer cancel()
		_ = server.ReportTraffic(reportContext, authorizationRequest.ConnectionID, traffic)
	}
}

func offersSubprotocol(header, expected string) bool {
	for _, candidate := range strings.Split(header, ",") {
		if strings.TrimSpace(candidate) == expected {
			return true
		}
	}

	return false
}

var ErrNonLoopbackAddress = errors.New("native client listener must use a loopback address")

const metadataMagic = "CRUCIBLE-NATIVE-1\n"

type ConnectionMetadata struct {
	AuthAttemptID                string `json:"auth_attempt_id"`
	Protocol                     string `json:"protocol"`
	ProxyConnectionID            string `json:"proxy_connection_id"`
	ProtocolAuthenticationSecret string `json:"protocol_authentication_secret"`
}

func WriteConnectionMetadata(connection net.Conn, metadata ConnectionMetadata) error {
	payload, err := json.Marshal(metadata)
	if err != nil || len(payload) > 64<<10 {
		return errors.New("invalid native connection metadata")
	}
	if _, err = connection.Write(append([]byte(metadataMagic), append(payload, '\n')...)); err != nil {
		return err
	}

	return nil
}

func ReadConnectionMetadata(connection net.Conn) (ConnectionMetadata, *bufio.Reader, error) {
	reader := bufio.NewReaderSize(connection, 64<<10)
	magic, err := reader.ReadString('\n')
	if err != nil || magic != metadataMagic {
		return ConnectionMetadata{}, nil, errors.New("invalid native connection metadata")
	}
	payload, err := reader.ReadBytes('\n')
	if err != nil || len(payload) > 64<<10 {
		return ConnectionMetadata{}, nil, errors.New("invalid native connection metadata")
	}
	var metadata ConnectionMetadata
	if err := json.Unmarshal(payload, &metadata); err != nil || metadata.AuthAttemptID == "" || (metadata.Protocol != "postgresql" && metadata.Protocol != "mysql") {
		return ConnectionMetadata{}, nil, errors.New("invalid native connection metadata")
	}

	return metadata, reader, nil
}
