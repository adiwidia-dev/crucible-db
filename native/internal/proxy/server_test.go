package proxy_test

import (
	"context"
	"errors"
	"net"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"github.com/adiwidia-dev/crucible-db/native/internal/proxy"
)

func testConfig() proxy.Config {
	return proxy.Config{
		PostgreSQLListenAddress: "127.0.0.1:0",
		MySQLListenAddress:      "127.0.0.1:0",
		GatewayListenAddress:    "127.0.0.1:0",
		ControlURL:              "http://laravel.test",
		ControlSecret:           "secret",
		RedisURL:                "redis://redis.test:6379/0",
		RevocationChannel:       "native-proxy:lease-revoked",
		ProxyID:                 "proxy-1",
		MaxConnections:          10,
		MaxConnectionsPerLease:  3,
		MaxConnectionsPerUser:   5,
		IdleTimeout:             15 * time.Minute,
		DrainTimeout:            time.Second,
		ReadinessInterval:       time.Second,
	}
}

func TestServerRemainsUnreadyWhenDependenciesAreUnavailable(t *testing.T) {
	server, err := proxy.NewServer(testConfig(), http.NotFoundHandler())
	if err != nil {
		t.Fatal(err)
	}
	server.SetReadinessCheck(func(context.Context) error { return errors.New("control unavailable") })
	ctx, cancel := context.WithCancel(context.Background())
	result := make(chan error, 1)
	go func() { result <- server.Run(ctx) }()
	time.Sleep(25 * time.Millisecond)
	if server.Health().Ready() {
		t.Fatal("expected dependency failure to keep the proxy unready")
	}
	cancel()
	if err := <-result; err != nil {
		t.Fatal(err)
	}
}

func TestServerForcesActiveConnectionsClosedAfterDrainTimeout(t *testing.T) {
	config := testConfig()
	addresses := availableAddresses(t, 3)
	config.PostgreSQLListenAddress = addresses[0]
	config.MySQLListenAddress = addresses[1]
	config.GatewayListenAddress = addresses[2]
	config.DrainTimeout = 50 * time.Millisecond
	server, err := proxy.NewServer(config, http.NotFoundHandler())
	if err != nil {
		t.Fatal(err)
	}
	accepted := make(chan struct{})
	handler := func(ctx context.Context, listener net.Listener) error {
		connection, acceptErr := listener.Accept()
		if acceptErr != nil {
			return acceptErr
		}
		close(accepted)
		buffer := make([]byte, 1)
		_, readErr := connection.Read(buffer)
		return readErr
	}
	server.SetProtocolHandlers(handler, nil)
	runContext, cancel := context.WithCancel(context.Background())
	result := make(chan error, 1)
	go func() { result <- server.Run(runContext) }()

	var connection net.Conn
	deadline := time.Now().Add(time.Second)
	for time.Now().Before(deadline) {
		connection, err = net.Dial("tcp", config.PostgreSQLListenAddress)
		if err == nil {
			break
		}
		time.Sleep(10 * time.Millisecond)
	}
	if connection == nil {
		t.Fatal("could not connect to proxy listener")
	}
	defer connection.Close()
	<-accepted
	cancel()
	select {
	case runErr := <-result:
		if runErr != nil {
			t.Fatal(runErr)
		}
	case <-time.After(time.Second):
		t.Fatal("server did not force-close the active connection after its drain deadline")
	}
}

func availableAddresses(t *testing.T, count int) []string {
	t.Helper()
	listeners := make([]net.Listener, 0, count)
	addresses := make([]string, 0, count)
	for range count {
		listener, err := net.Listen("tcp", "127.0.0.1:0")
		if err != nil {
			t.Fatal(err)
		}
		listeners = append(listeners, listener)
		addresses = append(addresses, listener.Addr().String())
	}
	for _, listener := range listeners {
		if err := listener.Close(); err != nil {
			t.Fatal(err)
		}
	}

	return addresses
}

func TestConfigRejectsUnsafeOrIncompleteValues(t *testing.T) {
	config := testConfig()
	config.MaxConnections = 0
	if err := config.Validate(); err == nil {
		t.Fatal("expected invalid limits to be rejected")
	}
	config = testConfig()
	config.PostgreSQLListenAddress = "bad-address"
	if err := config.Validate(); err == nil {
		t.Fatal("expected malformed listener to be rejected")
	}
}

func TestConnectionRegistryEnforcesGlobalLeaseAndUserLimits(t *testing.T) {
	registry, err := proxy.NewConnectionRegistry(proxy.Limits{Global: 2, Lease: 1, User: 1})
	if err != nil {
		t.Fatal(err)
	}
	if err := registry.Reserve(proxy.ConnectionIdentity{ID: "one", LeaseID: "lease-a", UserID: "user-a"}); err != nil {
		t.Fatal(err)
	}
	if err := registry.Reserve(proxy.ConnectionIdentity{ID: "two", LeaseID: "lease-a", UserID: "user-b"}); !errors.Is(err, proxy.ErrConnectionLimitReached) {
		t.Fatalf("expected lease limit, got %v", err)
	}
	registry.Release("one")
	if err := registry.Reserve(proxy.ConnectionIdentity{ID: "two", LeaseID: "lease-b", UserID: "user-b"}); err != nil {
		t.Fatal(err)
	}
	if registry.Count() != 1 {
		t.Fatalf("unexpected registry count: %d", registry.Count())
	}
}

func TestMetricsExposeBoundedConnectionAndRuntimeSeries(t *testing.T) {
	metrics := proxy.NewMetrics()
	metrics.SetConnections(2)
	recorder := httptest.NewRecorder()
	metrics.Handler().ServeHTTP(recorder, httptest.NewRequest(http.MethodGet, "/metrics", nil))

	if recorder.Code != http.StatusOK {
		t.Fatalf("expected metrics status 200, got %d", recorder.Code)
	}
	for _, series := range []string{"crucible_native_proxy_active_connections 2", "go_goroutines", "process_cpu_seconds_total"} {
		if !strings.Contains(recorder.Body.String(), series) {
			t.Fatalf("expected metrics output to contain %q", series)
		}
	}
}

func TestServerDrainsCleanlyOnCancellation(t *testing.T) {
	server, err := proxy.NewServer(testConfig(), http.NotFoundHandler())
	if err != nil {
		t.Fatal(err)
	}
	context, cancel := context.WithCancel(context.Background())
	result := make(chan error, 1)
	go func() { result <- server.Run(context) }()
	time.Sleep(25 * time.Millisecond)
	cancel()
	select {
	case err := <-result:
		if err != nil {
			t.Fatal(err)
		}
	case <-time.After(time.Second):
		t.Fatal("server did not drain after context cancellation")
	}
}
