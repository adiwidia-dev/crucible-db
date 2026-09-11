//go:build integration

package integration

import (
	"context"
	"encoding/json"
	"fmt"
	"net"
	"net/http"
	"os"
	"os/exec"
	"strings"
	"sync/atomic"
	"testing"
	"time"

	"github.com/adiwidia-dev/crucible-db/native/internal/cli"
	"github.com/adiwidia-dev/crucible-db/native/internal/control"
	"github.com/adiwidia-dev/crucible-db/native/internal/tunnel"
	"github.com/coder/websocket"
)

type harness struct {
	baseURL       string
	redisURL      string
	controlSecret string
}

const (
	postgresLeaseID      = "01ARZ3NDEKTSV4RRFFQ69G5FA1"
	mySQLLeaseID         = "01ARZ3NDEKTSV4RRFFQ69G5FA2"
	postgresWriteLeaseID = "01ARZ3NDEKTSV4RRFFQ69G5FA3"
	mySQLWriteLeaseID    = "01ARZ3NDEKTSV4RRFFQ69G5FA4"
	postgresDevice       = "native-integration-postgresql-device-code-0001"
	mySQLDevice          = "native-integration-mysql-device-code-00000002"
	postgresWriteDevice  = "native-integration-postgresql-write-device-0003"
	mySQLWriteDevice     = "native-integration-mysql-write-device-code-0004"
	syntheticPassword    = "crucible-native-integration-password"
)

func integrationHarness(t *testing.T) harness {
	t.Helper()

	if os.Getenv("NATIVE_INTEGRATION") != "1" {
		t.Skip("set NATIVE_INTEGRATION=1 to run against the Compose qualification targets")
	}

	return harness{
		baseURL:       environment("NATIVE_INTEGRATION_BASE_URL", "http://localhost:8000"),
		redisURL:      environment("NATIVE_INTEGRATION_REDIS_URL", "redis://127.0.0.1:6379/0"),
		controlSecret: environment("NATIVE_INTEGRATION_CONTROL_SECRET", "change-me-before-production"),
	}
}

func (harness harness) discovery(t *testing.T) map[string]any {
	t.Helper()

	client := &http.Client{Timeout: 5 * time.Second}
	response, err := client.Get(harness.baseURL + "/.well-known/crucible-native-client.json")
	if err != nil {
		t.Fatalf("request native discovery: %v", err)
	}
	defer response.Body.Close()
	if response.StatusCode != http.StatusOK {
		t.Fatalf("native discovery returned %s", response.Status)
	}

	var payload map[string]any
	if err := json.NewDecoder(response.Body).Decode(&payload); err != nil {
		t.Fatalf("decode native discovery: %v", err)
	}

	return payload
}

func (harness harness) discoveryDocument(t *testing.T) cli.DiscoveryDocument {
	t.Helper()

	document, err := (cli.HTTPClient{}).Discover(context.Background(), harness.baseURL)
	if err != nil {
		t.Fatalf("discover native client endpoints: %v", err)
	}

	return document
}

func (harness harness) issueDeviceToken(t *testing.T, deviceCode string) (cli.DiscoveryDocument, control.DeviceTokenResponse) {
	t.Helper()

	document := harness.discoveryDocument(t)
	token, err := (cli.HTTPClient{}).PollDeviceToken(context.Background(), document.TokenEndpoint, control.DeviceTokenRequest{DeviceCode: deviceCode})
	if err != nil {
		t.Fatalf("issue approved native device token: %v", err)
	}
	if token.AccessToken == "" || token.DeviceID == "" {
		t.Fatalf("native device token response is incomplete: %#v", token)
	}

	return document, token
}

func (harness harness) startTunnel(t *testing.T, document cli.DiscoveryDocument, token control.DeviceTokenResponse, leaseID, protocol, connectionID string) (string, context.CancelFunc) {
	t.Helper()

	listener, err := tunnel.ListenLoopback("127.0.0.1:0")
	if err != nil {
		t.Fatalf("start loopback qualification listener: %v", err)
	}
	ctx, cancel := context.WithCancel(context.Background())
	tunnelURL, err := cli.ExpandTunnelEndpoint(document.TunnelEndpoint, leaseID)
	if err != nil {
		t.Fatalf("expand native tunnel endpoint: %v", err)
	}
	errC := make(chan error, 1)
	var connectionSequence atomic.Uint64
	go func() {
		errC <- tunnel.ServeLoopback(ctx, listener, func(connectionContext context.Context, _ net.Conn) (*websocket.Conn, error) {
			return tunnel.Dial(connectionContext, tunnelURL, tunnel.ClientConnection{
				LeaseID:          leaseID,
				DeviceID:         token.DeviceID,
				ConnectionID:     fmt.Sprintf("%s-%d", connectionID, connectionSequence.Add(1)),
				BearerToken:      token.AccessToken,
				DatabaseProtocol: protocol,
			})
		})
	}()

	return listener.Addr().String(), func() {
		cancel()
		_ = listener.Close()
		select {
		case err := <-errC:
			if err != nil {
				t.Errorf("stop loopback qualification listener: %v", err)
			}
		case <-time.After(3 * time.Second):
			t.Error("loopback qualification listener did not stop")
		}
	}
}

func runContainerClient(t *testing.T, image string, environmentVariables []string, arguments []string, input string) string {
	t.Helper()
	if os.Getenv("NATIVE_INTEGRATION_CONTAINER_CLIENTS") != "1" {
		t.Skip("set NATIVE_INTEGRATION_CONTAINER_CLIENTS=1 to qualify real database client executables")
	}
	if strings.TrimSpace(image) == "" {
		t.Fatal("container client image must be configured")
	}

	ctx, cancel := context.WithTimeout(context.Background(), 30*time.Second)
	defer cancel()
	commandArguments := []string{"run", "--rm", "--network", "host", "-i"}
	for _, variable := range environmentVariables {
		commandArguments = append(commandArguments, "--env", variable)
	}
	commandArguments = append(commandArguments, image)
	commandArguments = append(commandArguments, arguments...)
	command := exec.CommandContext(ctx, "docker", commandArguments...)
	command.Stdin = strings.NewReader(input)
	output, err := command.CombinedOutput()
	if ctx.Err() != nil {
		t.Fatalf("container client timed out: %v\n%s", ctx.Err(), output)
	}
	if err != nil {
		t.Fatalf("container client failed: %v\n%s", err, output)
	}

	return string(output)
}

func environment(key string, fallback string) string {
	if value := strings.TrimSpace(os.Getenv(key)); value != "" {
		return value
	}

	return fallback
}
