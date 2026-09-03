//go:build integration

package integration

import (
	"context"
	"net/url"
	"testing"

	"github.com/adiwidia-dev/crucible-db/native/internal/cli"
	"github.com/adiwidia-dev/crucible-db/native/internal/control"
)

func TestNativeDiscoveryUsesFixedSameOriginPaths(t *testing.T) {
	harness := integrationHarness(t)
	payload := harness.discovery(t)
	if payload["protocol"] != "crucible.tunnel.v1" {
		t.Fatalf("unexpected tunnel protocol: %v", payload["protocol"])
	}

	baseURL, err := url.Parse(harness.baseURL)
	if err != nil {
		t.Fatalf("parse base URL: %v", err)
	}
	for field, path := range map[string]string{
		"device_authorization_endpoint": "/native-tunnel/v1/device/authorize",
		"token_endpoint":                "/native-tunnel/v1/device/token",
		"tunnel_endpoint":               "/native-tunnel/v1/tunnel/{lease_id}",
		"verification_uri":              "/native-proxy/device-authorizations/confirm",
	} {
		raw, ok := payload[field].(string)
		if !ok {
			t.Fatalf("discovery field %s is missing", field)
		}
		endpoint, err := url.Parse(raw)
		if err != nil {
			t.Fatalf("parse %s: %v", field, err)
		}
		if endpoint.Scheme != baseURL.Scheme || endpoint.Host != baseURL.Host || endpoint.Path != path {
			t.Fatalf("%s must remain same-origin at %s, got %s", field, path, raw)
		}
	}
}

func TestDeviceAuthorizationContractAcceptsRequiredCLIMetadata(t *testing.T) {
	harness := integrationHarness(t)
	document := harness.discoveryDocument(t)
	challenge, err := (cli.HTTPClient{}).StartDeviceAuthorization(context.Background(), document.DeviceAuthorizationEndpoint, control.DeviceAuthorizationRequest{
		LeaseID:         postgresLeaseID,
		CLIVersion:      "integration",
		OperatingSystem: "linux",
		Architecture:    "amd64",
	})
	if err != nil {
		t.Fatalf("start device authorization through same-origin gateway: %v", err)
	}
	if challenge.DeviceCode == "" || challenge.Protocol != "postgresql" {
		t.Fatalf("unexpected device authorization challenge: %#v", challenge)
	}
}
