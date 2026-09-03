package cli

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"runtime"
	"strings"
	"time"

	"github.com/adiwidia-dev/crucible-db/native/internal/control"
	"github.com/adiwidia-dev/crucible-db/native/internal/tunnel"
	"github.com/adiwidia-dev/crucible-db/native/internal/version"
)

const maxDiscoveryBytes = 64 << 10

type DiscoveryDocument struct {
	Protocol                    string `json:"protocol"`
	DeviceAuthorizationEndpoint string `json:"device_authorization_endpoint"`
	TokenEndpoint               string `json:"token_endpoint"`
	TunnelEndpoint              string `json:"tunnel_endpoint"`
	LeaseHeartbeatEndpoint      string `json:"lease_heartbeat_endpoint"`
	VerificationURI             string `json:"verification_uri"`
	LatestCLIVersion            string `json:"latest_cli_version"`
}

type DiscoveryClient interface {
	Discover(context.Context, string) (DiscoveryDocument, error)
}

type HTTPClient struct {
	Client *http.Client
}

func (client HTTPClient) Discover(ctx context.Context, server string) (DiscoveryDocument, error) {
	base, err := url.Parse(server)
	if err != nil || base.Scheme == "" || base.Host == "" {
		return DiscoveryDocument{}, errors.New("invalid Crucible server URL")
	}
	discoveryURL := base.ResolveReference(&url.URL{Path: "/.well-known/crucible-native-client.json"})
	request, err := http.NewRequestWithContext(ctx, http.MethodGet, discoveryURL.String(), nil)
	if err != nil {
		return DiscoveryDocument{}, err
	}
	response, err := client.httpClient().Do(request)
	if err != nil {
		return DiscoveryDocument{}, fmt.Errorf("fetch native client discovery: %w", err)
	}
	defer response.Body.Close()
	if response.StatusCode != http.StatusOK {
		return DiscoveryDocument{}, errors.New("native client discovery is unavailable")
	}
	body, err := io.ReadAll(io.LimitReader(response.Body, maxDiscoveryBytes+1))
	if err != nil || len(body) > maxDiscoveryBytes {
		return DiscoveryDocument{}, errors.New("invalid native client discovery response")
	}
	var document DiscoveryDocument
	if err := json.Unmarshal(body, &document); err != nil {
		return DiscoveryDocument{}, errors.New("invalid native client discovery response")
	}
	if document.Protocol != tunnel.CurrentProtocol || !sameOrigin(base, document.DeviceAuthorizationEndpoint) || !sameOrigin(base, document.TokenEndpoint) || !sameOrigin(base, document.TunnelEndpoint) || !sameOrigin(base, document.LeaseHeartbeatEndpoint) {
		return DiscoveryDocument{}, errors.New("unsupported native client discovery document")
	}

	return document, nil
}

func (client HTTPClient) StartDeviceAuthorization(ctx context.Context, endpoint string, input control.DeviceAuthorizationRequest) (control.DeviceAuthorizationResponse, error) {
	var result control.DeviceAuthorizationResponse
	err := client.doJSON(ctx, endpoint, input, &result, http.StatusCreated)

	return result, err
}

func (client HTTPClient) PollDeviceToken(ctx context.Context, endpoint string, input control.DeviceTokenRequest) (control.DeviceTokenResponse, error) {
	var result control.DeviceTokenResponse
	err := client.doJSON(ctx, endpoint, input, &result, http.StatusOK, http.StatusBadRequest)

	return result, err
}

func (client HTTPClient) HeartbeatLease(ctx context.Context, endpoint, accessToken, deviceID string) (control.LeaseHeartbeatResponse, error) {
	request, err := http.NewRequestWithContext(ctx, http.MethodPost, endpoint, http.NoBody)
	if err != nil {
		return control.LeaseHeartbeatResponse{}, err
	}
	request.Header.Set("Accept", "application/json")
	request.Header.Set("Authorization", "Bearer "+accessToken)
	request.Header.Set("X-Crucible-Device-Id", deviceID)
	response, err := client.httpClient().Do(request)
	if err != nil {
		return control.LeaseHeartbeatResponse{}, fmt.Errorf("native client lease heartbeat failed: %w", err)
	}
	defer response.Body.Close()
	if response.StatusCode == http.StatusUnauthorized || response.StatusCode == http.StatusForbidden {
		return control.LeaseHeartbeatResponse{Status: "revoked", Continue: false}, nil
	}
	if response.StatusCode != http.StatusOK {
		return control.LeaseHeartbeatResponse{}, errors.New("native client lease heartbeat was rejected")
	}
	responseBody, err := io.ReadAll(io.LimitReader(response.Body, maxDiscoveryBytes+1))
	if err != nil || len(responseBody) > maxDiscoveryBytes {
		return control.LeaseHeartbeatResponse{}, errors.New("invalid native client lease heartbeat response")
	}
	var result control.LeaseHeartbeatResponse
	if err := json.Unmarshal(responseBody, &result); err != nil {
		return control.LeaseHeartbeatResponse{}, errors.New("invalid native client lease heartbeat response")
	}

	return result, nil
}

func (client HTTPClient) doJSON(ctx context.Context, endpoint string, input, output any, allowedStatuses ...int) error {
	body, err := json.Marshal(input)
	if err != nil {
		return err
	}
	request, err := http.NewRequestWithContext(ctx, http.MethodPost, endpoint, bytes.NewReader(body))
	if err != nil {
		return err
	}
	request.Header.Set("Content-Type", "application/json")
	request.Header.Set("Accept", "application/json")
	response, err := client.httpClient().Do(request)
	if err != nil {
		return fmt.Errorf("native client request failed: %w", err)
	}
	defer response.Body.Close()
	allowed := false
	for _, status := range allowedStatuses {
		allowed = allowed || response.StatusCode == status
	}
	if !allowed {
		return errors.New("native client request was rejected")
	}
	responseBody, err := io.ReadAll(io.LimitReader(response.Body, maxDiscoveryBytes+1))
	if err != nil || len(responseBody) > maxDiscoveryBytes {
		return errors.New("invalid native client response")
	}
	if err := json.Unmarshal(responseBody, output); err != nil {
		return errors.New("invalid native client response")
	}

	return nil
}

func (client HTTPClient) httpClient() *http.Client {
	if client.Client != nil {
		return client.Client
	}

	return &http.Client{Timeout: 10 * time.Second}
}

func sameOrigin(base *url.URL, rawURL string) bool {
	endpoint, err := url.Parse(rawURL)

	return err == nil && endpoint.Scheme == base.Scheme && endpoint.Host == base.Host && strings.HasPrefix(endpoint.Path, "/native-tunnel/v1/")
}

func ExpandTunnelEndpoint(endpoint, leaseID string) (string, error) {
	return expandLeaseEndpoint(endpoint, leaseID, "native client tunnel endpoint is missing its lease placeholder")
}

func ExpandLeaseHeartbeatEndpoint(endpoint, leaseID string) (string, error) {
	return expandLeaseEndpoint(endpoint, leaseID, "native client lease heartbeat endpoint is missing its lease placeholder")
}

func expandLeaseEndpoint(endpoint, leaseID, missingPlaceholderMessage string) (string, error) {
	escapedLeaseID := url.PathEscape(leaseID)
	for _, placeholder := range []string{"{lease_id}", "%7Blease_id%7D", "%7blease_id%7d"} {
		if strings.Contains(endpoint, placeholder) {
			return strings.Replace(endpoint, placeholder, escapedLeaseID, 1), nil
		}
	}

	return "", errors.New(missingPlaceholderMessage)
}

func defaultDeviceInput(leaseID string) control.DeviceAuthorizationRequest {
	return control.DeviceAuthorizationRequest{
		LeaseID:         leaseID,
		CLIVersion:      version.Version,
		OperatingSystem: runtime.GOOS,
		Architecture:    runtime.GOARCH,
	}
}
