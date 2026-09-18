package cli_test

import (
	"bytes"
	"context"
	"errors"
	"net"
	"strings"
	"testing"
	"time"

	"github.com/adiwidia-dev/crucible-db/native/internal/cli"
	"github.com/adiwidia-dev/crucible-db/native/internal/control"
	"github.com/adiwidia-dev/crucible-db/native/internal/tunnel"
	"github.com/adiwidia-dev/crucible-db/native/internal/version"
)

type fakeClient struct {
	discovery cli.DiscoveryDocument
	start     control.DeviceAuthorizationRequest
	token     control.DeviceTokenResponse
	starts    int
	polls     int
	heartbeat control.LeaseHeartbeatResponse
}

type memoryDeviceCredentialStore struct {
	credential cli.ApprovedDeviceCredential
	found      bool
}

func (store *memoryDeviceCredentialStore) Load(server, leaseID string) (cli.ApprovedDeviceCredential, bool, error) {
	found := store.found && strings.TrimRight(server, "/") == store.credential.Server && leaseID == store.credential.LeaseID

	return store.credential, found, nil
}

func (store *memoryDeviceCredentialStore) Save(credential cli.ApprovedDeviceCredential) error {
	store.credential = credential
	store.found = true

	return nil
}

func (client *fakeClient) Discover(_ context.Context, _ string) (cli.DiscoveryDocument, error) {
	return client.discovery, nil
}

func (client *fakeClient) StartDeviceAuthorization(_ context.Context, _ string, input control.DeviceAuthorizationRequest) (control.DeviceAuthorizationResponse, error) {
	client.start = input
	client.starts++

	return control.DeviceAuthorizationResponse{DeviceCode: "device-code", UserCode: "ABCD-EFGH", VerificationURI: "https://crucible.example.test/native-proxy/device-authorizations/confirm", VerificationURIComplete: "https://crucible.example.test/native-proxy/device-authorizations/confirm?user_code=ABCD-EFGH", Interval: 5, Protocol: "postgresql"}, nil
}

func (client *fakeClient) PollDeviceToken(_ context.Context, _ string, _ control.DeviceTokenRequest) (control.DeviceTokenResponse, error) {
	client.polls++
	if client.token.AccessToken != "" {
		return client.token, nil
	}

	return control.DeviceTokenResponse{AccessToken: "never-print-this-token", TokenType: "Bearer", ExpiresIn: 300, DeviceID: "device-1"}, nil
}

func (client *fakeClient) HeartbeatLease(_ context.Context, _ string, _, _ string) (control.LeaseHeartbeatResponse, error) {
	return client.heartbeat, nil
}

func TestConnectRequiresHTTPSByDefault(t *testing.T) {
	command := cli.NewRootCommand(cli.Dependencies{Stdout: &bytes.Buffer{}, Stderr: &bytes.Buffer{}})
	command.SetArgs([]string{"connect", "--server", "http://127.0.0.1:8080", "--lease", "lease-1"})

	if err := command.Execute(); err == nil || !strings.Contains(err.Error(), "HTTPS") {
		t.Fatalf("expected HTTPS validation error, got %v", err)
	}
}

func TestExpandTunnelEndpointAcceptsLiteralAndLegacyEncodedPlaceholders(t *testing.T) {
	for _, endpoint := range []string{
		"https://crucible.example.test/native-tunnel/v1/tunnel/{lease_id}",
		"https://crucible.example.test/native-tunnel/v1/tunnel/%7Blease_id%7D",
	} {
		expanded, err := cli.ExpandTunnelEndpoint(endpoint, "lease-1")
		if err != nil || expanded != "https://crucible.example.test/native-tunnel/v1/tunnel/lease-1" {
			t.Fatalf("unexpected expanded tunnel endpoint %q: %v", expanded, err)
		}
	}
}

func TestConnectUsesSameOriginDiscoveryAndNeverPrintsBearerToken(t *testing.T) {
	client := &fakeClient{discovery: cli.DiscoveryDocument{
		Protocol:                    tunnel.CurrentProtocol,
		DeviceAuthorizationEndpoint: "https://crucible.example.test/native-tunnel/v1/device/authorize",
		TokenEndpoint:               "https://crucible.example.test/native-tunnel/v1/device/token",
		TunnelEndpoint:              "https://crucible.example.test/native-tunnel/v1/tunnel/{lease_id}",
		LeaseHeartbeatEndpoint:      "https://crucible.example.test/native-tunnel/v1/leases/{lease_id}/heartbeat",
	}}
	var stdout bytes.Buffer
	command := cli.NewRootCommand(cli.Dependencies{
		DeviceClient:      client,
		DeviceCredentials: &memoryDeviceCredentialStore{},
		DiscoveryClient:   client,
		Stdout:            &stdout,
		Stderr:            &bytes.Buffer{},
		AllowInsecureHTTP: true,
		OpenBrowser:       func(string) error { return errors.New("browser unavailable") },
		Sleep:             func(context.Context, time.Duration) error { return nil },
		ListenLoopback: func(string) (net.Listener, error) {
			return tunnel.ListenLoopback("127.0.0.1:0")
		},
		ServeLoopback: func(_ context.Context, listener net.Listener, _ tunnel.Opener) error {
			return listener.Close()
		},
	})
	command.SetArgs([]string{"connect", "--server", "http://crucible.example.test", "--lease", "lease-1", "--json"})

	if err := command.Execute(); err != nil {
		t.Fatal(err)
	}
	if client.start.LeaseID != "lease-1" || client.start.CLIVersion != version.Version || client.start.CLIVersion == "" || client.polls != 1 {
		t.Fatalf("unexpected device flow: %#v polls=%d", client.start, client.polls)
	}
	if strings.Contains(stdout.String(), "never-print-this-token") || !strings.Contains(stdout.String(), "authorization_required") || !strings.Contains(stdout.String(), "authorized") {
		t.Fatalf("unexpected CLI output: %s", stdout.String())
	}
}

func TestConnectReportsItsLocalAddressAndAnAvailableUpdateForHumanOutput(t *testing.T) {
	previousVersion := version.Version
	version.Version = "0.1.0"
	t.Cleanup(func() { version.Version = previousVersion })
	client := &fakeClient{discovery: cli.DiscoveryDocument{
		Protocol:                    tunnel.CurrentProtocol,
		DeviceAuthorizationEndpoint: "https://crucible.example.test/native-tunnel/v1/device/authorize",
		TokenEndpoint:               "https://crucible.example.test/native-tunnel/v1/device/token",
		TunnelEndpoint:              "https://crucible.example.test/native-tunnel/v1/tunnel/{lease_id}",
		LeaseHeartbeatEndpoint:      "https://crucible.example.test/native-tunnel/v1/leases/{lease_id}/heartbeat",
		LatestCLIVersion:            "0.2.0",
	}}
	var stdout bytes.Buffer
	var openedURL string
	command := cli.NewRootCommand(cli.Dependencies{
		DeviceClient:      client,
		DeviceCredentials: &memoryDeviceCredentialStore{},
		DiscoveryClient:   client,
		Stdout:            &stdout,
		Stderr:            &bytes.Buffer{},
		AllowInsecureHTTP: true,
		OpenBrowser: func(url string) error {
			openedURL = url

			return nil
		},
		Sleep: func(context.Context, time.Duration) error { return nil },
		ListenLoopback: func(string) (net.Listener, error) {
			return tunnel.ListenLoopback("127.0.0.1:0")
		},
		ServeLoopback: func(_ context.Context, listener net.Listener, _ tunnel.Opener) error {
			return listener.Close()
		},
	})
	command.SetArgs([]string{"connect", "--server", "http://crucible.example.test", "--lease", "lease-1"})

	if err := command.Execute(); err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(stdout.String(), "A newer Crucible CLI version (0.2.0) is available.") || !strings.Contains(stdout.String(), "Listening for postgresql on 127.0.0.1:") {
		t.Fatalf("unexpected human CLI output: %s", stdout.String())
	}
	if !strings.Contains(stdout.String(), "Open this URL to approve the device:\n  https://crucible.example.test/native-proxy/device-authorizations/confirm\n\nDevice code:\n  ABCD-EFGH\n") {
		t.Fatalf("device authorization instructions are not scannable: %s", stdout.String())
	}
	if openedURL != "https://crucible.example.test/native-proxy/device-authorizations/confirm" {
		t.Fatalf("browser must open the verification page without the device code, got %s", openedURL)
	}
}

func TestConnectReusesAnApprovedDeviceForTheSameServerAndLease(t *testing.T) {
	discovery := cli.DiscoveryDocument{
		Protocol:                    tunnel.CurrentProtocol,
		DeviceAuthorizationEndpoint: "https://crucible.example.test/native-tunnel/v1/device/authorize",
		TokenEndpoint:               "https://crucible.example.test/native-tunnel/v1/device/token",
		TunnelEndpoint:              "https://crucible.example.test/native-tunnel/v1/tunnel/{lease_id}",
		LeaseHeartbeatEndpoint:      "https://crucible.example.test/native-tunnel/v1/leases/{lease_id}/heartbeat",
	}
	store := &memoryDeviceCredentialStore{}
	run := func(client *fakeClient, stdout *bytes.Buffer) error {
		command := cli.NewRootCommand(cli.Dependencies{
			DeviceClient:      client,
			DeviceCredentials: store,
			DiscoveryClient:   client,
			Stdout:            stdout,
			Stderr:            &bytes.Buffer{},
			AllowInsecureHTTP: true,
			OpenBrowser:       func(string) error { return nil },
			Sleep:             func(context.Context, time.Duration) error { return nil },
			ListenLoopback: func(string) (net.Listener, error) {
				return tunnel.ListenLoopback("127.0.0.1:0")
			},
			ServeLoopback: func(_ context.Context, listener net.Listener, _ tunnel.Opener) error {
				return listener.Close()
			},
		})
		command.SetArgs([]string{"connect", "--server", "http://crucible.example.test", "--lease", "lease-1"})

		return command.Execute()
	}

	firstClient := &fakeClient{discovery: discovery}
	if err := run(firstClient, &bytes.Buffer{}); err != nil {
		t.Fatal(err)
	}
	secondClient := &fakeClient{discovery: discovery}
	var secondOutput bytes.Buffer
	if err := run(secondClient, &secondOutput); err != nil {
		t.Fatal(err)
	}
	if firstClient.starts != 1 || secondClient.starts != 0 || secondClient.polls != 0 {
		t.Fatalf("unexpected repeated device flow: first starts=%d second starts=%d polls=%d", firstClient.starts, secondClient.starts, secondClient.polls)
	}
	if !strings.Contains(secondOutput.String(), "This CLI device is already approved for this access window.") || strings.Contains(secondOutput.String(), "Device code:") {
		t.Fatalf("unexpected repeated connection output: %s", secondOutput.String())
	}
}

func TestConnectClosesItsListenerWhenTheAccessWindowExpires(t *testing.T) {
	client := &fakeClient{
		discovery: cli.DiscoveryDocument{
			Protocol:                    tunnel.CurrentProtocol,
			DeviceAuthorizationEndpoint: "https://crucible.example.test/native-tunnel/v1/device/authorize",
			TokenEndpoint:               "https://crucible.example.test/native-tunnel/v1/device/token",
			TunnelEndpoint:              "https://crucible.example.test/native-tunnel/v1/tunnel/{lease_id}",
			LeaseHeartbeatEndpoint:      "https://crucible.example.test/native-tunnel/v1/leases/{lease_id}/heartbeat",
		},
		token: control.DeviceTokenResponse{
			AccessToken: "never-print-this-token",
			TokenType:   "Bearer",
			ExpiresIn:   1,
			DeviceID:    "device-1",
		},
	}
	var stdout bytes.Buffer
	command := cli.NewRootCommand(cli.Dependencies{
		DeviceClient:      client,
		DeviceCredentials: &memoryDeviceCredentialStore{},
		DiscoveryClient:   client,
		Stdout:            &stdout,
		Stderr:            &bytes.Buffer{},
		AllowInsecureHTTP: true,
		OpenBrowser:       func(string) error { return nil },
		Sleep:             func(context.Context, time.Duration) error { return nil },
		ListenLoopback: func(string) (net.Listener, error) {
			return tunnel.ListenLoopback("127.0.0.1:0")
		},
		ServeLoopback: func(ctx context.Context, _ net.Listener, _ tunnel.Opener) error {
			deadline, ok := ctx.Deadline()
			if !ok || time.Until(deadline) <= 0 || time.Until(deadline) > 2*time.Second {
				t.Fatalf("unexpected access deadline: %v ok=%t", deadline, ok)
			}
			<-ctx.Done()

			return nil
		},
	})
	command.SetArgs([]string{"connect", "--server", "http://crucible.example.test", "--lease", "lease-1"})

	if err := command.Execute(); err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(stdout.String(), "Access window ended. Local listener and database connections closed.") {
		t.Fatalf("missing access-expiry output: %s", stdout.String())
	}
}

func TestConnectClosesItsListenerWhenTheLeaseIsEndedInCrucible(t *testing.T) {
	client := &fakeClient{
		discovery: cli.DiscoveryDocument{
			Protocol:                    tunnel.CurrentProtocol,
			DeviceAuthorizationEndpoint: "https://crucible.example.test/native-tunnel/v1/device/authorize",
			TokenEndpoint:               "https://crucible.example.test/native-tunnel/v1/device/token",
			TunnelEndpoint:              "https://crucible.example.test/native-tunnel/v1/tunnel/{lease_id}",
			LeaseHeartbeatEndpoint:      "https://crucible.example.test/native-tunnel/v1/leases/{lease_id}/heartbeat",
		},
		heartbeat: control.LeaseHeartbeatResponse{Status: "revoked", Continue: false},
	}
	var stdout bytes.Buffer
	command := cli.NewRootCommand(cli.Dependencies{
		DeviceClient:      client,
		LeaseClient:       client,
		DeviceCredentials: &memoryDeviceCredentialStore{},
		DiscoveryClient:   client,
		Stdout:            &stdout,
		Stderr:            &bytes.Buffer{},
		AllowInsecureHTTP: true,
		OpenBrowser:       func(string) error { return nil },
		Sleep:             func(context.Context, time.Duration) error { return nil },
		HeartbeatInterval: time.Millisecond,
		ListenLoopback: func(string) (net.Listener, error) {
			return tunnel.ListenLoopback("127.0.0.1:0")
		},
		ServeLoopback: func(ctx context.Context, _ net.Listener, _ tunnel.Opener) error {
			<-ctx.Done()

			return nil
		},
	})
	command.SetArgs([]string{"connect", "--server", "http://crucible.example.test", "--lease", "lease-1"})

	if err := command.Execute(); err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(stdout.String(), "Access was ended in Crucible. Local listener and database connections closed.") {
		t.Fatalf("missing ended-access output: %s", stdout.String())
	}
}

func TestConnectRejectsAnUnreadableCustomServerAuthority(t *testing.T) {
	command := cli.NewRootCommand(cli.Dependencies{Stdout: &bytes.Buffer{}, Stderr: &bytes.Buffer{}})
	command.SetArgs([]string{"connect", "--server", "https://crucible.example.test", "--lease", "lease-1", "--ca-file", "/missing/crucible-ca.pem"})

	if err := command.Execute(); err == nil || !strings.Contains(err.Error(), "read --ca-file") {
		t.Fatalf("expected a custom CA file error, got %v", err)
	}
}

func TestVersionSubcommandPrintsTheCLIRelease(t *testing.T) {
	var stdout bytes.Buffer
	command := cli.NewRootCommand(cli.Dependencies{Stdout: &stdout, Stderr: &bytes.Buffer{}})
	command.SetArgs([]string{"version"})

	if err := command.Execute(); err != nil {
		t.Fatal(err)
	}
	if stdout.String() != version.Version+"\n" {
		t.Fatalf("unexpected version output: %q", stdout.String())
	}
}

func TestRootHelpAndVersionFlagsAreAvailable(t *testing.T) {
	var helpOutput bytes.Buffer
	helpCommand := cli.NewRootCommand(cli.Dependencies{Stdout: &helpOutput, Stderr: &bytes.Buffer{}})
	helpCommand.SetArgs([]string{"--help"})

	if err := helpCommand.Execute(); err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(helpOutput.String(), "connect") || !strings.Contains(helpOutput.String(), "--version") {
		t.Fatalf("unexpected help output: %s", helpOutput.String())
	}

	var versionOutput bytes.Buffer
	versionCommand := cli.NewRootCommand(cli.Dependencies{Stdout: &versionOutput, Stderr: &bytes.Buffer{}})
	versionCommand.SetArgs([]string{"--version"})

	if err := versionCommand.Execute(); err != nil {
		t.Fatal(err)
	}
	if versionOutput.String() != version.Version+"\n" {
		t.Fatalf("unexpected version flag output: %q", versionOutput.String())
	}
}
