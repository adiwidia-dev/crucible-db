package control

import (
	"bytes"
	"context"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"time"
)

const maxResponseBytes = 1 << 20

var ErrUnauthorized = errors.New("native proxy control request was rejected")

type HTTPDoer interface {
	Do(*http.Request) (*http.Response, error)
}

type Client struct {
	BaseURL      *url.URL
	Secret       string
	ProxyID      string
	HTTPClient   HTTPDoer
	Now          func() time.Time
	NewRequestID func() (string, error)
}

func NewClient(baseURL, secret, proxyID string) (*Client, error) {
	parsed, err := url.Parse(baseURL)
	if err != nil || parsed.Scheme == "" || parsed.Host == "" {
		return nil, errors.New("invalid native proxy control URL")
	}

	return &Client{
		BaseURL:      parsed,
		Secret:       secret,
		ProxyID:      proxyID,
		HTTPClient:   &http.Client{Timeout: 10 * time.Second},
		Now:          time.Now,
		NewRequestID: newRequestID,
	}, nil
}

func (client *Client) StartDeviceAuthorization(ctx context.Context, input DeviceAuthorizationRequest) (DeviceAuthorizationResponse, error) {
	var result DeviceAuthorizationResponse
	err := client.doJSON(ctx, http.MethodPost, "/internal/native-proxy/v1/device-authorizations", input, &result)

	return result, err
}

func (client *Client) Ping(ctx context.Context) error {
	return client.doJSON(ctx, http.MethodGet, "/internal/native-proxy/v1/health", nil, nil)
}

func (client *Client) PollDeviceToken(ctx context.Context, input DeviceTokenRequest) (DeviceTokenResponse, error) {
	var result DeviceTokenResponse
	err := client.doJSON(ctx, http.MethodPost, "/internal/native-proxy/v1/device-token", input, &result)

	return result, err
}

func (client *Client) HeartbeatLease(ctx context.Context, input LeaseHeartbeatRequest) (LeaseHeartbeatResponse, error) {
	var result LeaseHeartbeatResponse
	err := client.doJSON(ctx, http.MethodPost, "/internal/native-proxy/v1/leases/heartbeat", input, &result)

	return result, err
}

func (client *Client) AuthorizeTunnel(ctx context.Context, input TunnelAuthorizationRequest) (TunnelAuthorizationResponse, error) {
	var result TunnelAuthorizationResponse
	err := client.doJSON(ctx, http.MethodPost, "/internal/native-proxy/v1/tunnels/authorize", input, &result)

	return result, err
}

func (client *Client) AuthorizeConnection(ctx context.Context, input ConnectionAuthorizationRequest) (ConnectionAuthorizationResponse, error) {
	var result ConnectionAuthorizationResponse
	err := client.doJSON(ctx, http.MethodPost, "/internal/native-proxy/v1/connections", input, &result)

	return result, err
}

func (client *Client) FetchUpstreamMaterial(ctx context.Context, connectionID string) (ConnectionUpstreamMaterialResponse, error) {
	var result ConnectionUpstreamMaterialResponse
	err := client.doJSON(ctx, http.MethodPost, "/internal/native-proxy/v1/connections/"+connectionID+"/upstream", ConnectionLifecycleRequest{ProxyID: client.ProxyID}, &result)

	return result, err
}

func (client *Client) MarkConnectionAuthenticated(ctx context.Context, connectionID, clientApplication, clientVersion string) error {
	return client.doJSON(ctx, http.MethodPost, "/internal/native-proxy/v1/connections/"+connectionID+"/authenticated", ConnectionAuthenticatedRequest{
		ProxyID: client.ProxyID, ClientApplication: clientApplication, ClientVersion: clientVersion,
	}, nil)
}

func (client *Client) HeartbeatConnection(ctx context.Context, connectionID string) (ConnectionHeartbeatResponse, error) {
	var result ConnectionHeartbeatResponse
	err := client.doJSON(ctx, http.MethodPost, "/internal/native-proxy/v1/connections/"+connectionID+"/heartbeat", ConnectionLifecycleRequest{ProxyID: client.ProxyID}, &result)

	return result, err
}

func (client *Client) CloseConnection(ctx context.Context, connectionID, reason string) error {
	return client.doJSON(ctx, http.MethodDelete, "/internal/native-proxy/v1/connections/"+connectionID, ConnectionLifecycleRequest{ProxyID: client.ProxyID, Reason: reason}, nil)
}

func (client *Client) RecordConnectionTraffic(ctx context.Context, proxyConnectionID string, bytesReceived, bytesSent int64) error {
	return client.doJSON(ctx, http.MethodPost, "/internal/native-proxy/v1/connections/traffic", ConnectionTrafficRequest{
		ProxyID: client.ProxyID, ProxyConnectionID: proxyConnectionID, BytesReceived: bytesReceived, BytesSent: bytesSent,
	}, nil)
}

func (client *Client) ValidateStatement(ctx context.Context, connectionID string, input StatementRequest) (StatementDecision, error) {
	var result StatementDecision
	err := client.doJSON(ctx, http.MethodPost, "/internal/native-proxy/v1/connections/"+connectionID+"/statements/validate", input, &result)
	return result, err
}

func (client *Client) AuthorizeStatement(ctx context.Context, connectionID string, input StatementRequest) (StatementAuthorization, error) {
	var result StatementAuthorization
	err := client.doJSON(ctx, http.MethodPost, "/internal/native-proxy/v1/connections/"+connectionID+"/statements", input, &result)
	return result, err
}

func (client *Client) CompleteStatement(ctx context.Context, connectionID string, statementID int, input StatementOutcome) error {
	return client.doJSON(ctx, http.MethodPost, fmt.Sprintf("/internal/native-proxy/v1/connections/%s/statements/%d/complete", connectionID, statementID), input, nil)
}

func (client *Client) doJSON(ctx context.Context, method, path string, input, output any) error {
	if client == nil || client.BaseURL == nil || client.Secret == "" || client.ProxyID == "" || client.HTTPClient == nil || client.Now == nil || client.NewRequestID == nil {
		return errors.New("native proxy control client is not configured")
	}

	body, err := json.Marshal(input)
	if err != nil {
		return fmt.Errorf("encode native proxy control request: %w", err)
	}
	requestID, err := client.NewRequestID()
	if err != nil {
		return fmt.Errorf("create native proxy request id: %w", err)
	}
	timestamp := fmt.Sprintf("%d", client.Now().Unix())
	target := client.BaseURL.ResolveReference(&url.URL{Path: path})
	signature := Signature(client.Secret, method, target.EscapedPath(), timestamp, requestID, body)
	var response *http.Response
	for attempt := range 2 {
		request, requestErr := http.NewRequestWithContext(ctx, method, target.String(), bytes.NewReader(body))
		if requestErr != nil {
			return fmt.Errorf("create native proxy control request: %w", requestErr)
		}
		request.Header.Set("Content-Type", "application/json")
		request.Header.Set("Accept", "application/json")
		request.Header.Set("X-Crucible-Proxy-Id", client.ProxyID)
		request.Header.Set("X-Crucible-Timestamp", timestamp)
		request.Header.Set("X-Crucible-Request-Id", requestID)
		request.Header.Set("X-Crucible-Signature", signature)

		response, err = client.HTTPClient.Do(request)
		if err != nil && response != nil && response.Body != nil {
			response.Body.Close()
			response = nil
		}
		if err == nil || attempt == 1 || ctx.Err() != nil {
			break
		}
	}
	if err != nil {
		return fmt.Errorf("native proxy control request failed: %w", err)
	}
	defer response.Body.Close()

	responseBody, err := io.ReadAll(io.LimitReader(response.Body, maxResponseBytes+1))
	if err != nil {
		return fmt.Errorf("read native proxy control response: %w", err)
	}
	if len(responseBody) > maxResponseBytes {
		return errors.New("native proxy control response exceeded size limit")
	}

	if response.StatusCode == http.StatusUnauthorized || response.StatusCode == http.StatusForbidden {
		return ErrUnauthorized
	}
	if response.StatusCode == http.StatusBadRequest {
		if tokenResponse, isTokenResponse := output.(*DeviceTokenResponse); isTokenResponse {
			if err := json.Unmarshal(responseBody, tokenResponse); err != nil {
				return fmt.Errorf("decode native proxy control response: %w", err)
			}
			if tokenResponse.Error != "" {
				return nil
			}
		}
	}
	if response.StatusCode < http.StatusOK || response.StatusCode >= http.StatusMultipleChoices {
		return fmt.Errorf("native proxy control request failed with status %d", response.StatusCode)
	}
	if output == nil {
		return nil
	}
	if err := json.Unmarshal(responseBody, output); err != nil {
		return fmt.Errorf("decode native proxy control response: %w", err)
	}

	return nil
}

func newRequestID() (string, error) {
	bytes := make([]byte, 24)
	if _, err := rand.Read(bytes); err != nil {
		return "", err
	}

	return hex.EncodeToString(bytes), nil
}
