package main

import (
	"context"
	"errors"
	"fmt"
	"net"
	"net/http"
	"os"
	"os/signal"
	"strconv"
	"syscall"
	"time"

	"github.com/adiwidia-dev/crucible-db/native/internal/command"
	"github.com/adiwidia-dev/crucible-db/native/internal/control"
	"github.com/adiwidia-dev/crucible-db/native/internal/gateway"
	"github.com/adiwidia-dev/crucible-db/native/internal/mysql"
	"github.com/adiwidia-dev/crucible-db/native/internal/postgres"
	"github.com/adiwidia-dev/crucible-db/native/internal/proxy"
	"github.com/adiwidia-dev/crucible-db/native/internal/version"
)

func main() {
	if len(os.Args) == 2 && os.Args[1] == "version" {
		fmt.Println(version.Version)

		return
	}
	if len(os.Args) == 2 && os.Args[1] == "healthcheck" {
		os.Exit(command.Run(runHealthcheck, os.Stderr))
	}

	os.Exit(command.Run(run, os.Stderr))
}

func run() error {
	configuration, publicURL, err := configurationFromEnvironment()
	if err != nil {
		return err
	}
	controlClient, err := control.NewClient(configuration.ControlURL, configuration.ControlSecret, configuration.ProxyID)
	if err != nil {
		return err
	}
	tunnelHandler := gateway.NewTunnelHandler(controlClient, configuration.ProxyID, configuration.PostgreSQLListenAddress, configuration.MySQLListenAddress)
	gatewayServer, err := gateway.NewServer(publicURL, controlClient, tunnelHandler)
	if err != nil {
		return err
	}
	server, err := proxy.NewServer(configuration, gatewayServer.Handler())
	if err != nil {
		return err
	}
	server.SetReadinessCheck(func(ctx context.Context) error {
		if err := controlClient.Ping(ctx); err != nil {
			return err
		}

		return proxy.CheckRedis(ctx, configuration.RedisURL)
	})
	postgreSQLServer := &postgres.Server{Control: controlClient, ProxyID: configuration.ProxyID, Registry: server.Registry()}
	mySQLServer := &mysql.Server{Control: controlClient, ProxyID: configuration.ProxyID, Registry: server.Registry()}
	server.SetProtocolHandlers(postgreSQLServer.Serve, mySQLServer.Serve)
	context, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()

	return server.Run(context)
}

func configurationFromEnvironment() (proxy.Config, string, error) {
	maxConnections, err := positiveEnvironmentInt("NATIVE_PROXY_MAX_CONNECTIONS", 100)
	if err != nil {
		return proxy.Config{}, "", err
	}
	idleTimeout, err := positiveEnvironmentDuration("NATIVE_PROXY_IDLE_TIMEOUT", 15*time.Minute)
	if err != nil {
		return proxy.Config{}, "", err
	}
	drainTimeout, err := positiveEnvironmentDuration("NATIVE_PROXY_DRAIN_TIMEOUT", 30*time.Second)
	if err != nil {
		return proxy.Config{}, "", err
	}
	readinessInterval, err := positiveEnvironmentDuration("NATIVE_PROXY_READINESS_INTERVAL", 5*time.Second)
	if err != nil {
		return proxy.Config{}, "", err
	}
	maxPerLease, err := positiveEnvironmentInt("NATIVE_PROXY_MAX_CONNECTIONS_PER_LEASE", 3)
	if err != nil {
		return proxy.Config{}, "", err
	}
	maxPerUser, err := positiveEnvironmentInt("NATIVE_PROXY_MAX_CONNECTIONS_PER_USER", 10)
	if err != nil {
		return proxy.Config{}, "", err
	}
	configuration := proxy.Config{
		PostgreSQLListenAddress: environment("NATIVE_PROXY_POSTGRES_LISTEN", ":5432"),
		MySQLListenAddress:      environment("NATIVE_PROXY_MYSQL_LISTEN", ":3306"),
		GatewayListenAddress:    environment("NATIVE_PROXY_GATEWAY_LISTEN", ":8081"),
		ControlURL:              os.Getenv("NATIVE_PROXY_CONTROL_URL"),
		ControlSecret:           os.Getenv("NATIVE_PROXY_CONTROL_SECRET"),
		RedisURL:                os.Getenv("NATIVE_PROXY_REDIS_URL"),
		RevocationChannel:       environment("NATIVE_PROXY_REVOCATION_CHANNEL", "native-proxy:lease-revoked"),
		ProxyID:                 os.Getenv("NATIVE_PROXY_ID"),
		MaxConnections:          maxConnections,
		MaxConnectionsPerLease:  maxPerLease,
		MaxConnectionsPerUser:   maxPerUser,
		IdleTimeout:             idleTimeout,
		DrainTimeout:            drainTimeout,
		ReadinessInterval:       readinessInterval,
	}
	publicURL := os.Getenv("APP_URL")
	if err := configuration.Validate(); err != nil || publicURL == "" {
		if err != nil {
			return proxy.Config{}, "", err
		}

		return proxy.Config{}, "", errors.New("APP_URL is required")
	}

	return configuration, publicURL, nil
}

func positiveEnvironmentDuration(key string, fallback time.Duration) (time.Duration, error) {
	value := environment(key, fallback.String())
	parsed, err := time.ParseDuration(value)
	if err != nil || parsed <= 0 {
		return 0, errors.New(key + " must be a positive duration")
	}

	return parsed, nil
}

func runHealthcheck() error {
	address := environment("NATIVE_PROXY_GATEWAY_LISTEN", ":8081")
	host, port, err := net.SplitHostPort(address)
	if err != nil {
		return errors.New("NATIVE_PROXY_GATEWAY_LISTEN is invalid")
	}
	if host == "" || host == "0.0.0.0" || host == "::" {
		host = "127.0.0.1"
	}
	client := &http.Client{Timeout: 2 * time.Second}
	response, err := client.Get("http://" + net.JoinHostPort(host, port) + "/readyz")
	if err != nil {
		return errors.New("native proxy readiness endpoint is unavailable")
	}
	defer response.Body.Close()
	if response.StatusCode != http.StatusOK {
		return fmt.Errorf("native proxy readiness returned status %d", response.StatusCode)
	}

	return nil
}

func environment(key, fallback string) string {
	if value := os.Getenv(key); value != "" {
		return value
	}

	return fallback
}

func positiveEnvironmentInt(key string, fallback int) (int, error) {
	value := environment(key, strconv.Itoa(fallback))
	parsed, err := strconv.Atoi(value)
	if err != nil || parsed < 1 {
		return 0, errors.New(key + " must be a positive integer")
	}

	return parsed, nil
}
