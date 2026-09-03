package gateway

import (
	"context"
	"crypto/sha256"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strings"

	"github.com/adiwidia-dev/crucible-db/native/internal/control"
	"github.com/adiwidia-dev/crucible-db/native/internal/tunnel"
	"github.com/adiwidia-dev/crucible-db/native/internal/version"
)

const maxRequestBytes = 64 << 10

type Server struct {
	publicOrigin *url.URL
	control      DeviceControlClient
	tunnel       http.Handler
}

type DeviceControlClient interface {
	StartDeviceAuthorization(context.Context, control.DeviceAuthorizationRequest) (control.DeviceAuthorizationResponse, error)
	PollDeviceToken(context.Context, control.DeviceTokenRequest) (control.DeviceTokenResponse, error)
	HeartbeatLease(context.Context, control.LeaseHeartbeatRequest) (control.LeaseHeartbeatResponse, error)
}

type TunnelControlClient interface {
	AuthorizeTunnel(context.Context, control.TunnelAuthorizationRequest) (control.TunnelAuthorizationResponse, error)
}

type TrafficControlClient interface {
	RecordConnectionTraffic(context.Context, string, int64, int64) error
}

func NewServer(publicOrigin string, controlClient DeviceControlClient, tunnelHandler ...http.Handler) (*Server, error) {
	origin, err := url.Parse(publicOrigin)
	if err != nil || origin.Scheme == "" || origin.Host == "" {
		return nil, errors.New("invalid public origin")
	}
	if controlClient == nil {
		return nil, errors.New("native proxy control client is required")
	}

	server := &Server{publicOrigin: origin, control: controlClient}
	if len(tunnelHandler) == 1 {
		server.tunnel = tunnelHandler[0]
	}

	return server, nil
}

func (server *Server) Handler() http.Handler {
	mux := http.NewServeMux()
	mux.HandleFunc("GET /.well-known/crucible-native-client.json", server.discovery)
	mux.HandleFunc("POST /native-tunnel/v1/device/authorize", server.startDeviceAuthorization)
	mux.HandleFunc("POST /native-tunnel/v1/device/token", server.pollDeviceToken)
	mux.HandleFunc("POST /native-tunnel/v1/leases/{lease_id}/heartbeat", server.heartbeatLease)
	mux.Handle("/native-tunnel/v1/tunnel/", http.HandlerFunc(func(writer http.ResponseWriter, request *http.Request) {
		if server.tunnel == nil {
			server.tunnelUnavailable(writer, request)

			return
		}
		server.tunnel.ServeHTTP(writer, request)
	}))

	return mux
}

func NewTunnelHandler(controlClient TunnelControlClient, proxyID, postgreSQLAddress, mySQLAddress string) http.Handler {
	var reportTraffic func(context.Context, string, tunnel.Traffic) error
	if trafficControl, ok := controlClient.(TrafficControlClient); ok {
		reportTraffic = func(ctx context.Context, connectionID string, traffic tunnel.Traffic) error {
			return trafficControl.RecordConnectionTraffic(ctx, connectionID, traffic.BytesReceived, traffic.BytesSent)
		}
	}

	return tunnel.Server{
		Authorize: func(ctx context.Context, request tunnel.AuthorizationRequest) (tunnel.AuthorizationResult, error) {
			if controlClient == nil || proxyID == "" {
				return tunnel.AuthorizationResult{}, control.ErrUnauthorized
			}
			bearerHash := sha256.Sum256([]byte(request.BearerToken))
			result, err := controlClient.AuthorizeTunnel(ctx, control.TunnelAuthorizationRequest{
				LeaseID:       request.LeaseID,
				DeviceID:      request.DeviceID,
				ConnectionID:  request.ConnectionID,
				Protocol:      request.DatabaseProtocol,
				ProxyID:       proxyID,
				BearerHash:    fmt.Sprintf("%x", bearerHash),
				RemoteAddress: request.RemoteAddr,
			})
			if err != nil || result.LeaseID != request.LeaseID || result.ProxyConnectionID != request.ConnectionID || result.Protocol != request.DatabaseProtocol {
				return tunnel.AuthorizationResult{}, control.ErrUnauthorized
			}
			upstreamAddress := postgreSQLAddress
			if request.DatabaseProtocol == "mysql" {
				upstreamAddress = mySQLAddress
			}
			if upstreamAddress == "" || (request.DatabaseProtocol != "postgresql" && request.DatabaseProtocol != "mysql") {
				return tunnel.AuthorizationResult{}, control.ErrUnauthorized
			}

			return tunnel.AuthorizationResult{
				UpstreamAddress:              upstreamAddress,
				AuthAttemptID:                result.AuthAttemptID,
				ProtocolAuthenticationSecret: result.ProtocolAuthenticationSecret,
			}, nil
		},
		ReportTraffic: reportTraffic,
	}.Handler()
}

func (server *Server) discovery(writer http.ResponseWriter, request *http.Request) {
	server.writeJSON(writer, http.StatusOK, map[string]string{
		"protocol":                      tunnel.CurrentProtocol,
		"device_authorization_endpoint": server.url("/native-tunnel/v1/device/authorize"),
		"token_endpoint":                server.url("/native-tunnel/v1/device/token"),
		"tunnel_endpoint":               server.templateURL("/native-tunnel/v1/tunnel/__LEASE_ID__"),
		"lease_heartbeat_endpoint":      server.templateURL("/native-tunnel/v1/leases/__LEASE_ID__/heartbeat"),
		"verification_uri":              server.url("/native-proxy/device-authorizations/confirm"),
		"latest_cli_version":            version.Version,
	})
}

func (server *Server) heartbeatLease(writer http.ResponseWriter, request *http.Request) {
	leaseID := strings.TrimSpace(request.PathValue("lease_id"))
	deviceID := strings.TrimSpace(request.Header.Get("X-Crucible-Device-Id"))
	authorization := strings.TrimSpace(request.Header.Get("Authorization"))
	if leaseID == "" || deviceID == "" || !strings.HasPrefix(authorization, "Bearer ") {
		server.writeJSON(writer, http.StatusBadRequest, map[string]string{"error": "invalid_request"})

		return
	}
	bearerToken := strings.TrimSpace(strings.TrimPrefix(authorization, "Bearer "))
	if bearerToken == "" {
		server.writeJSON(writer, http.StatusBadRequest, map[string]string{"error": "invalid_request"})

		return
	}
	bearerHash := sha256.Sum256([]byte(bearerToken))
	result, err := server.control.HeartbeatLease(request.Context(), control.LeaseHeartbeatRequest{
		LeaseID:    leaseID,
		DeviceID:   deviceID,
		BearerHash: fmt.Sprintf("%x", bearerHash),
	})
	if err != nil {
		server.writeControlError(writer, err)

		return
	}

	server.writeJSON(writer, http.StatusOK, result)
}

func (server *Server) startDeviceAuthorization(writer http.ResponseWriter, request *http.Request) {
	var input control.DeviceAuthorizationRequest
	if !decodeJSON(request, &input) || strings.TrimSpace(input.LeaseID) == "" {
		server.writeJSON(writer, http.StatusBadRequest, map[string]string{"error": "invalid_request"})

		return
	}

	result, err := server.control.StartDeviceAuthorization(request.Context(), input)
	if err != nil {
		server.writeControlError(writer, err)

		return
	}
	result.VerificationURI = server.url("/native-proxy/device-authorizations/confirm")
	result.VerificationURIComplete = server.urlWithQuery("/native-proxy/device-authorizations/confirm", url.Values{
		"user_code": []string{result.UserCode},
	})

	server.writeJSON(writer, http.StatusCreated, result)
}

func (server *Server) pollDeviceToken(writer http.ResponseWriter, request *http.Request) {
	var input control.DeviceTokenRequest
	if !decodeJSON(request, &input) || strings.TrimSpace(input.DeviceCode) == "" {
		server.writeJSON(writer, http.StatusBadRequest, map[string]string{"error": "invalid_request"})

		return
	}

	result, err := server.control.PollDeviceToken(request.Context(), input)
	if err != nil {
		server.writeControlError(writer, err)

		return
	}
	if result.Error != "" {
		server.writeJSON(writer, http.StatusBadRequest, result)

		return
	}

	server.writeJSON(writer, http.StatusOK, result)
}

func (server *Server) tunnelUnavailable(writer http.ResponseWriter, request *http.Request) {
	server.writeJSON(writer, http.StatusServiceUnavailable, map[string]string{"error": "native_tunnel_unavailable"})
}

func (server *Server) writeControlError(writer http.ResponseWriter, err error) {
	if errors.Is(err, control.ErrUnauthorized) {
		server.writeJSON(writer, http.StatusUnauthorized, map[string]string{"error": "unauthorized"})

		return
	}

	server.writeJSON(writer, http.StatusBadGateway, map[string]string{"error": "service_unavailable"})
}

func (server *Server) writeJSON(writer http.ResponseWriter, status int, value any) {
	writer.Header().Set("Cache-Control", "no-store, private")
	writer.Header().Set("Content-Type", "application/json")
	writer.WriteHeader(status)
	_ = json.NewEncoder(writer).Encode(value)
}

func (server *Server) url(path string) string {
	copy := *server.publicOrigin
	copy.Path = path
	copy.RawQuery = ""
	copy.Fragment = ""

	return copy.String()
}

func (server *Server) urlWithQuery(path string, query url.Values) string {
	copy := *server.publicOrigin
	copy.Path = path
	copy.RawQuery = query.Encode()
	copy.Fragment = ""

	return copy.String()
}

func (server *Server) templateURL(path string) string {
	return strings.Replace(server.url(path), "__LEASE_ID__", "{lease_id}", 1)
}

func decodeJSON(request *http.Request, target any) bool {
	request.Body = http.MaxBytesReader(nil, request.Body, maxRequestBytes)
	decoder := json.NewDecoder(request.Body)
	decoder.DisallowUnknownFields()
	if err := decoder.Decode(target); err != nil {
		return false
	}

	return decoder.Decode(&struct{}{}) == io.EOF
}
