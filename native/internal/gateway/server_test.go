package gateway_test

import (
	"context"
	"encoding/json"
	"errors"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/adiwidia-dev/crucible-db/native/internal/control"
	"github.com/adiwidia-dev/crucible-db/native/internal/gateway"
	"github.com/adiwidia-dev/crucible-db/native/internal/tunnel"
)

type fakeControlClient struct {
	deviceAuthorizationInput control.DeviceAuthorizationRequest
	deviceAuthorization      control.DeviceAuthorizationResponse
	deviceTokenInput         control.DeviceTokenRequest
	deviceToken              control.DeviceTokenResponse
	leaseHeartbeatInput      control.LeaseHeartbeatRequest
	leaseHeartbeat           control.LeaseHeartbeatResponse
	err                      error
}

func (client *fakeControlClient) HeartbeatLease(_ context.Context, input control.LeaseHeartbeatRequest) (control.LeaseHeartbeatResponse, error) {
	client.leaseHeartbeatInput = input

	return client.leaseHeartbeat, client.err
}

func (client *fakeControlClient) StartDeviceAuthorization(_ context.Context, input control.DeviceAuthorizationRequest) (control.DeviceAuthorizationResponse, error) {
	client.deviceAuthorizationInput = input

	return client.deviceAuthorization, client.err
}

func (client *fakeControlClient) PollDeviceToken(_ context.Context, input control.DeviceTokenRequest) (control.DeviceTokenResponse, error) {
	client.deviceTokenInput = input

	return client.deviceToken, client.err
}

func TestGatewayPublishesSameOriginDiscoveryDocument(t *testing.T) {
	server, err := gateway.NewServer("https://crucible.example.test", &fakeControlClient{})
	if err != nil {
		t.Fatal(err)
	}

	response := httptest.NewRecorder()
	server.Handler().ServeHTTP(response, httptest.NewRequest(http.MethodGet, "/.well-known/crucible-native-client.json", nil))

	if response.Code != http.StatusOK || response.Header().Get("Cache-Control") != "no-store, private" {
		t.Fatalf("unexpected discovery response: %d %q", response.Code, response.Header().Get("Cache-Control"))
	}
	var document map[string]string
	if err := json.Unmarshal(response.Body.Bytes(), &document); err != nil {
		t.Fatal(err)
	}
	if document["protocol"] != tunnel.CurrentProtocol || document["token_endpoint"] != "https://crucible.example.test/native-tunnel/v1/device/token" || document["tunnel_endpoint"] != "https://crucible.example.test/native-tunnel/v1/tunnel/{lease_id}" || document["lease_heartbeat_endpoint"] != "https://crucible.example.test/native-tunnel/v1/leases/{lease_id}/heartbeat" {
		t.Fatalf("unexpected discovery document: %#v", document)
	}
}

func TestGatewayAuthenticatesAndForwardsLeaseHeartbeats(t *testing.T) {
	controlClient := &fakeControlClient{leaseHeartbeat: control.LeaseHeartbeatResponse{Status: "continue", Continue: true}}
	server, err := gateway.NewServer("https://crucible.example.test", controlClient)
	if err != nil {
		t.Fatal(err)
	}
	request := httptest.NewRequest(http.MethodPost, "/native-tunnel/v1/leases/lease-1/heartbeat", nil)
	request.Header.Set("Authorization", "Bearer never-forward-this-token")
	request.Header.Set("X-Crucible-Device-Id", "device-1")
	response := httptest.NewRecorder()

	server.Handler().ServeHTTP(response, request)

	if response.Code != http.StatusOK || !strings.Contains(response.Body.String(), `"continue":true`) {
		t.Fatalf("unexpected heartbeat response: %d %s", response.Code, response.Body.String())
	}
	if controlClient.leaseHeartbeatInput.LeaseID != "lease-1" || controlClient.leaseHeartbeatInput.DeviceID != "device-1" || controlClient.leaseHeartbeatInput.BearerHash != "d7d1463494a2f25a0ff652a331fe374a382490293a1084e0be2afc8ba550a896" {
		t.Fatalf("unexpected heartbeat input: %#v", controlClient.leaseHeartbeatInput)
	}
}

func TestGatewayForwardsOnlyDeviceAuthorizationFields(t *testing.T) {
	controlClient := &fakeControlClient{deviceAuthorization: control.DeviceAuthorizationResponse{DeviceCode: "device-code", UserCode: "ABCD-EFGH", ExpiresIn: 300, Interval: 5}}
	server, err := gateway.NewServer("https://crucible.example.test", controlClient)
	if err != nil {
		t.Fatal(err)
	}
	request := httptest.NewRequest(http.MethodPost, "/native-tunnel/v1/device/authorize", strings.NewReader(`{"lease_id":"lease-1","cli_version":"0.1.0","unexpected":"must-not-pass"}`))
	request.Header.Set("Content-Type", "application/json")
	response := httptest.NewRecorder()

	server.Handler().ServeHTTP(response, request)

	if response.Code != http.StatusBadRequest {
		t.Fatalf("unexpected response status: %d", response.Code)
	}
	if controlClient.deviceAuthorizationInput.LeaseID != "" {
		t.Fatalf("gateway forwarded a request with unexpected input: %#v", controlClient.deviceAuthorizationInput)
	}
}

func TestGatewayNormalizesDeviceAuthorizationVerificationURLs(t *testing.T) {
	controlClient := &fakeControlClient{deviceAuthorization: control.DeviceAuthorizationResponse{
		DeviceCode:              "device-code",
		UserCode:                "ABCD-EFGH",
		VerificationURI:         "http://app:8000/native-proxy/device-authorizations/confirm",
		VerificationURIComplete: "http://app:8000/native-proxy/device-authorizations/confirm?user_code=ABCD-EFGH",
		ExpiresIn:               300,
		Interval:                5,
		Protocol:                "postgresql",
	}}
	server, err := gateway.NewServer("https://crucible.example.test", controlClient)
	if err != nil {
		t.Fatal(err)
	}
	request := httptest.NewRequest(http.MethodPost, "/native-tunnel/v1/device/authorize", strings.NewReader(`{"lease_id":"lease-1","cli_version":"0.1.0"}`))
	request.Header.Set("Content-Type", "application/json")
	response := httptest.NewRecorder()

	server.Handler().ServeHTTP(response, request)

	if response.Code != http.StatusCreated {
		t.Fatalf("unexpected response status: %d", response.Code)
	}
	var result control.DeviceAuthorizationResponse
	if err := json.Unmarshal(response.Body.Bytes(), &result); err != nil {
		t.Fatal(err)
	}
	if result.VerificationURI != "https://crucible.example.test/native-proxy/device-authorizations/confirm" {
		t.Fatalf("unexpected verification URI: %s", result.VerificationURI)
	}
	if result.VerificationURIComplete != "https://crucible.example.test/native-proxy/device-authorizations/confirm?user_code=ABCD-EFGH" {
		t.Fatalf("unexpected complete verification URI: %s", result.VerificationURIComplete)
	}
}

func TestGatewaySanitizesControlErrors(t *testing.T) {
	controlClient := &fakeControlClient{err: errors.New("postgres://username:password@upstream.example.test:5432")}
	server, err := gateway.NewServer("https://crucible.example.test", controlClient)
	if err != nil {
		t.Fatal(err)
	}
	request := httptest.NewRequest(http.MethodPost, "/native-tunnel/v1/device/authorize", strings.NewReader(`{"lease_id":"lease-1"}`))
	response := httptest.NewRecorder()

	server.Handler().ServeHTTP(response, request)

	if response.Code != http.StatusBadGateway || strings.Contains(response.Body.String(), "upstream.example.test") {
		t.Fatalf("gateway leaked its control error: %d %s", response.Code, response.Body.String())
	}
}

func TestGatewayReturnsDevicePollingState(t *testing.T) {
	controlClient := &fakeControlClient{deviceToken: control.DeviceTokenResponse{Error: "slow_down", Interval: 10}}
	server, err := gateway.NewServer("https://crucible.example.test", controlClient)
	if err != nil {
		t.Fatal(err)
	}
	request := httptest.NewRequest(http.MethodPost, "/native-tunnel/v1/device/token", strings.NewReader(`{"device_code":"device-code"}`))
	response := httptest.NewRecorder()

	server.Handler().ServeHTTP(response, request)

	if response.Code != http.StatusBadRequest || !strings.Contains(response.Body.String(), "slow_down") {
		t.Fatalf("unexpected token response: %d %s", response.Code, response.Body.String())
	}
}
