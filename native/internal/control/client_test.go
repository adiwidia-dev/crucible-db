package control_test

import (
	"context"
	"crypto/aes"
	"crypto/cipher"
	"crypto/hmac"
	"crypto/rand"
	"crypto/sha256"
	"encoding/base64"
	"encoding/json"
	"errors"
	"fmt"
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
	t        *testing.T
	requests []*http.Request
}

func (client *retryingHTTPClient) Do(request *http.Request) (*http.Response, error) {
	client.requests = append(client.requests, request)
	if len(client.requests) == 1 {
		return nil, errors.New("temporary control-plane network failure")
	}

	body := protectedResponseBody(client.t, request, http.StatusCreated, []byte(`{"device_code":"device","user_code":"ABCD-EFGH","expires_in":300,"interval":5}`))

	return &http.Response{
		StatusCode: http.StatusCreated,
		Header:     http.Header{"Content-Type": []string{"application/json"}, "X-Crucible-Control-Protocol": []string{"2"}},
		Body:       io.NopCloser(strings.NewReader(string(body))),
	}, nil
}

func TestClientSignsEveryMutatingRequestWithANewRequestID(t *testing.T) {
	var requestIDs []string
	var mutex sync.Mutex
	server := httptest.NewServer(http.HandlerFunc(func(writer http.ResponseWriter, request *http.Request) {
		body, err := io.ReadAll(request.Body)
		if err != nil {
			t.Fatal(err)
		}
		requestID := request.Header.Get("X-Crucible-Request-Id")
		want := control.Signature("secret", request.Method, request.URL.EscapedPath(), request.Header.Get("X-Crucible-Timestamp"), requestID, body)
		if request.Header.Get("X-Crucible-Signature") != want {
			t.Fatal("request signature did not match Laravel canonical format")
		}
		if strings.Contains(string(body), "lease-01") {
			t.Fatal("sensitive request payload was sent in plaintext")
		}
		plaintext := decryptRequestBody(t, request, body)
		var input control.DeviceAuthorizationRequest
		if err := json.Unmarshal(plaintext, &input); err != nil || input.LeaseID != "lease-01" {
			t.Fatalf("unexpected protected request payload: %s", plaintext)
		}
		mutex.Lock()
		requestIDs = append(requestIDs, requestID)
		mutex.Unlock()
		writeProtectedJSON(t, writer, request, http.StatusOK, []byte(`{"device_code":"device","user_code":"ABCD-EFGH","expires_in":300,"interval":5}`))
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
		payload, err := json.Marshal(control.DeviceTokenResponse{Error: "slow_down", Interval: 10})
		if err != nil {
			t.Fatal(err)
		}
		writeProtectedJSON(t, writer, request, http.StatusBadRequest, payload)
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
	httpClient := &retryingHTTPClient{t: t}
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
			body, err := io.ReadAll(request.Body)
			if err != nil {
				t.Fatal(err)
			}
			if err := json.Unmarshal(decryptRequestBody(t, request, body), &captured.body); err != nil {
				t.Fatal(err)
			}
		}
		requests = append(requests, captured)
		writeProtectedJSON(t, writer, request, http.StatusOK, []byte(`{}`))
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

func TestClientRejectsTamperedControlResponses(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(writer http.ResponseWriter, request *http.Request) {
		body := protectedResponseBody(t, request, http.StatusOK, []byte(`{}`))
		body[len(body)-3] ^= 1
		writer.Header().Set("X-Crucible-Control-Protocol", "2")
		writer.WriteHeader(http.StatusOK)
		_, _ = writer.Write(body)
	}))
	defer server.Close()

	client, err := control.NewClient(server.URL, "secret", "proxy-1")
	if err != nil {
		t.Fatal(err)
	}
	if err := client.Ping(context.Background()); err == nil || !strings.Contains(err.Error(), "decrypt native proxy control response") {
		t.Fatalf("expected authenticated response rejection, got %v", err)
	}
}

func decryptRequestBody(t testing.TB, request *http.Request, body []byte) []byte {
	t.Helper()
	var envelope struct {
		Version    int    `json:"version"`
		Nonce      string `json:"nonce"`
		Ciphertext string `json:"ciphertext"`
		Tag        string `json:"tag"`
	}
	if err := json.Unmarshal(body, &envelope); err != nil || envelope.Version != 2 {
		t.Fatalf("invalid encrypted request envelope: %v", err)
	}
	nonce, err := base64.StdEncoding.DecodeString(envelope.Nonce)
	if err != nil {
		t.Fatal(err)
	}
	ciphertext, err := base64.StdEncoding.DecodeString(envelope.Ciphertext)
	if err != nil {
		t.Fatal(err)
	}
	tag, err := base64.StdEncoding.DecodeString(envelope.Tag)
	if err != nil {
		t.Fatal(err)
	}

	keyDerivation := hmac.New(sha256.New, []byte("secret"))
	_, _ = keyDerivation.Write([]byte("crucible-native-proxy-control-request-v2"))
	block, err := aes.NewCipher(keyDerivation.Sum(nil))
	if err != nil {
		t.Fatal(err)
	}
	gcm, err := cipher.NewGCM(block)
	if err != nil {
		t.Fatal(err)
	}
	aad := strings.Join([]string{
		"2",
		request.Header.Get("X-Crucible-Proxy-Id"),
		request.Method,
		request.URL.EscapedPath(),
		request.Header.Get("X-Crucible-Timestamp"),
		request.Header.Get("X-Crucible-Request-Id"),
	}, "\n")
	plaintext, err := gcm.Open(nil, nonce, append(ciphertext, tag...), []byte(aad))
	if err != nil {
		t.Fatal(err)
	}

	return plaintext
}

func writeProtectedJSON(t testing.TB, writer http.ResponseWriter, request *http.Request, statusCode int, plaintext []byte) {
	t.Helper()
	writer.Header().Set("Content-Type", "application/json")
	writer.Header().Set("X-Crucible-Control-Protocol", "2")
	writer.WriteHeader(statusCode)
	_, _ = writer.Write(protectedResponseBody(t, request, statusCode, plaintext))
}

func protectedResponseBody(t testing.TB, request *http.Request, statusCode int, plaintext []byte) []byte {
	t.Helper()
	keyDerivation := hmac.New(sha256.New, []byte("secret"))
	_, _ = keyDerivation.Write([]byte("crucible-native-proxy-control-response-v2"))
	block, err := aes.NewCipher(keyDerivation.Sum(nil))
	if err != nil {
		t.Fatal(err)
	}
	gcm, err := cipher.NewGCM(block)
	if err != nil {
		t.Fatal(err)
	}
	nonce := make([]byte, gcm.NonceSize())
	if _, err := rand.Read(nonce); err != nil {
		t.Fatal(err)
	}
	aad := strings.Join([]string{
		"2",
		request.Header.Get("X-Crucible-Proxy-Id"),
		request.Header.Get("X-Crucible-Timestamp"),
		request.Header.Get("X-Crucible-Request-Id"),
		fmt.Sprintf("%d", statusCode),
	}, "\n")
	sealed := gcm.Seal(nil, nonce, plaintext, []byte(aad))
	ciphertext := sealed[:len(sealed)-gcm.Overhead()]
	tag := sealed[len(sealed)-gcm.Overhead():]
	body, err := json.Marshal(map[string]any{
		"version":    2,
		"nonce":      base64.StdEncoding.EncodeToString(nonce),
		"ciphertext": base64.StdEncoding.EncodeToString(ciphertext),
		"tag":        base64.StdEncoding.EncodeToString(tag),
	})
	if err != nil {
		t.Fatal(err)
	}

	return body
}
