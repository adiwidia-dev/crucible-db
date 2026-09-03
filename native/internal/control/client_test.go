package control_test

import (
	"context"
	"encoding/json"
	"errors"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/adiwidia-dev/crucible-db/native/internal/control"
)

type retryingHTTPClient struct {
	requests []*http.Request
}

func (client *retryingHTTPClient) Do(request *http.Request) (*http.Response, error) {
	client.requests = append(client.requests, request)
	if len(client.requests) == 1 {
		return nil, errors.New("temporary control-plane network failure")
	}

	return &http.Response{
		StatusCode: http.StatusCreated,
		Header:     http.Header{"Content-Type": []string{"application/json"}},
		Body:       io.NopCloser(strings.NewReader(`{"device_code":"device","user_code":"ABCD-EFGH","expires_in":300,"interval":5}`)),
	}, nil
}

func TestClientSignsEveryMutatingRequestWithANewRequestID(t *testing.T) {
	var requestIDs []string
	var mutex sync.Mutex
	server := httptest.NewServer(http.HandlerFunc(func(writer http.ResponseWriter, request *http.Request) {
		body := make([]byte, request.ContentLength)
		_, _ = request.Body.Read(body)
		requestID := request.Header.Get("X-Crucible-Request-Id")
		want := control.Signature("secret", request.Method, request.URL.EscapedPath(), request.Header.Get("X-Crucible-Timestamp"), requestID, body)
		if request.Header.Get("X-Crucible-Signature") != want {
			t.Fatal("request signature did not match Laravel canonical format")
		}
		mutex.Lock()
		requestIDs = append(requestIDs, requestID)
		mutex.Unlock()
		writer.Header().Set("Content-Type", "application/json")
		_, _ = writer.Write([]byte(`{"device_code":"device","user_code":"ABCD-EFGH","expires_in":300,"interval":5}`))
	}))
	defer server.Close()

	client, err := control.NewClient(server.URL, "secret", "proxy-1")
	if err != nil {
		t.Fatal(err)
	}
	client.Now = func() time.Time { return time.Unix(1_700_000_000, 0) }

	for range 2 {
		if _, err := client.StartDeviceAuthorization(context.Background(), control.DeviceAuthorizationRequest{LeaseID: "lease-01"}); err != nil {
			t.Fatal(err)
		}
	}

	if len(requestIDs) != 2 || requestIDs[0] == requestIDs[1] {
		t.Fatalf("request ids must be unique, got %#v", requestIDs)
	}
}

func TestClientReturnsDeviceAuthorizationPollErrorsWithoutLeakingControlDetails(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(writer http.ResponseWriter, request *http.Request) {
		writer.Header().Set("Content-Type", "application/json")
		writer.WriteHeader(http.StatusBadRequest)
		_ = json.NewEncoder(writer).Encode(control.DeviceTokenResponse{Error: "slow_down", Interval: 10})
	}))
	defer server.Close()

	client, err := control.NewClient(server.URL, "secret", "proxy-1")
	if err != nil {
		t.Fatal(err)
	}

	result, err := client.PollDeviceToken(context.Background(), control.DeviceTokenRequest{DeviceCode: "device-code"})
	if err != nil {
		t.Fatal(err)
	}
	if result.Error != "slow_down" || result.Interval != 10 {
		t.Fatalf("unexpected device poll result: %#v", result)
	}
}

func TestClientRetriesAnUncertainMutatingRequestWithTheSameReplayIdentity(t *testing.T) {
	client, err := control.NewClient("https://crucible.example.test", "secret", "proxy-1")
	if err != nil {
		t.Fatal(err)
	}
	httpClient := &retryingHTTPClient{}
	client.HTTPClient = httpClient
	client.Now = func() time.Time { return time.Unix(1_700_000_000, 0) }
	client.NewRequestID = func() (string, error) { return "stable-retry-request-id", nil }

	if _, err := client.StartDeviceAuthorization(context.Background(), control.DeviceAuthorizationRequest{LeaseID: "lease-01"}); err != nil {
		t.Fatal(err)
	}
	if len(httpClient.requests) != 2 {
		t.Fatalf("expected one retry, got %d requests", len(httpClient.requests))
	}
	for _, request := range httpClient.requests {
		if request.Header.Get("X-Crucible-Request-Id") != "stable-retry-request-id" {
			t.Fatalf("retry request id drifted: %q", request.Header.Get("X-Crucible-Request-Id"))
		}
		if request.Header.Get("X-Crucible-Signature") != httpClient.requests[0].Header.Get("X-Crucible-Signature") {
			t.Fatal("retry signature drifted")
		}
	}
}

func TestClientUsesSignedHealthAndLifecycleEndpoints(t *testing.T) {
	requests := make([]struct {
		method string
		path   string
		body   control.ConnectionTrafficRequest
	}, 0, 4)
	server := httptest.NewServer(http.HandlerFunc(func(writer http.ResponseWriter, request *http.Request) {
		captured := struct {
			method string
			path   string
			body   control.ConnectionTrafficRequest
		}{method: request.Method, path: request.URL.Path}
		if request.URL.Path == "/internal/native-proxy/v1/connections/traffic" {
			if err := json.NewDecoder(request.Body).Decode(&captured.body); err != nil {
				t.Fatal(err)
			}
		}
		requests = append(requests, captured)
		writer.Header().Set("Content-Type", "application/json")
		_, _ = writer.Write([]byte(`{}`))
	}))
	defer server.Close()

	client, err := control.NewClient(server.URL, "secret", "proxy-1")
	if err != nil {
		t.Fatal(err)
	}
	if err := client.Ping(context.Background()); err != nil {
		t.Fatal(err)
	}
	if err := client.MarkConnectionAuthenticated(context.Background(), "connection-1", "psql", "17.0"); err != nil {
		t.Fatal(err)
	}
	if err := client.CloseConnection(context.Background(), "connection-1", "client disconnected"); err != nil {
		t.Fatal(err)
	}
	if err := client.RecordConnectionTraffic(context.Background(), "client-visible-connection-1", 128, 256); err != nil {
		t.Fatal(err)
	}
	if len(requests) != 4 || requests[0].method != http.MethodGet || requests[0].path != "/internal/native-proxy/v1/health" || requests[1].method != http.MethodPost || requests[1].path != "/internal/native-proxy/v1/connections/connection-1/authenticated" || requests[2].method != http.MethodDelete || requests[2].path != "/internal/native-proxy/v1/connections/connection-1" || requests[3].method != http.MethodPost || requests[3].path != "/internal/native-proxy/v1/connections/traffic" {
		t.Fatalf("unexpected lifecycle requests: %#v", requests)
	}
	if requests[3].body.ProxyID != "proxy-1" || requests[3].body.ProxyConnectionID != "client-visible-connection-1" || requests[3].body.BytesReceived != 128 || requests[3].body.BytesSent != 256 {
		t.Fatalf("unexpected traffic payload: %#v", requests[3].body)
	}
}
