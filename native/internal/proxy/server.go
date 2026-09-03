package proxy

import (
	"context"
	"encoding/json"
	"errors"
	"net"
	"net/http"
	"sync"
	"time"

	"github.com/adiwidia-dev/crucible-db/native/internal/version"
)

type ListenerHandler func(context.Context, net.Listener) error
type ReadinessCheck func(context.Context) error

type Server struct {
	config           Config
	registry         *ConnectionRegistry
	health           *Health
	metrics          *Metrics
	handlePostgreSQL ListenerHandler
	handleMySQL      ListenerHandler
	gatewayHandler   http.Handler
	readinessCheck   ReadinessCheck
}

func NewServer(config Config, gatewayHandler http.Handler) (*Server, error) {
	if err := config.Validate(); err != nil {
		return nil, err
	}
	if gatewayHandler == nil {
		return nil, errors.New("native proxy gateway handler is required")
	}
	registry, err := NewConnectionRegistry(Limits{Global: config.MaxConnections, Lease: config.MaxConnectionsPerLease, User: config.MaxConnectionsPerUser})
	if err != nil {
		return nil, err
	}
	metrics := NewMetrics()
	health := &Health{}

	registry.SetCountObserver(metrics.SetConnections)

	return &Server{
		config:           config,
		registry:         registry,
		health:           health,
		metrics:          metrics,
		handlePostgreSQL: rejectProtocolConnections,
		handleMySQL:      rejectProtocolConnections,
		gatewayHandler:   gatewayHandler,
	}, nil
}

func (server *Server) SetReadinessCheck(check ReadinessCheck) {
	server.readinessCheck = check
}

func (server *Server) Registry() *ConnectionRegistry {
	return server.registry
}

func (server *Server) Health() *Health {
	return server.health
}

func (server *Server) SetProtocolHandlers(postgreSQL, mySQL ListenerHandler) {
	if postgreSQL != nil {
		server.handlePostgreSQL = postgreSQL
	}
	if mySQL != nil {
		server.handleMySQL = mySQL
	}
}

func (server *Server) Run(ctx context.Context) error {
	postgresListener, err := net.Listen("tcp", server.config.PostgreSQLListenAddress)
	if err != nil {
		return err
	}
	defer postgresListener.Close()
	mysqlListener, err := net.Listen("tcp", server.config.MySQLListenAddress)
	if err != nil {
		return err
	}
	defer mysqlListener.Close()
	gatewayListener, err := net.Listen("tcp", server.config.GatewayListenAddress)
	if err != nil {
		return err
	}
	defer gatewayListener.Close()

	mux := http.NewServeMux()
	mux.Handle("/metrics", server.metrics.Handler())
	mux.HandleFunc("GET /healthz", func(writer http.ResponseWriter, request *http.Request) {
		server.writeHealth(writer, http.StatusOK)
	})
	mux.HandleFunc("GET /readyz", func(writer http.ResponseWriter, request *http.Request) {
		if !server.health.Ready() {
			server.writeHealth(writer, http.StatusServiceUnavailable)

			return
		}
		server.writeHealth(writer, http.StatusOK)
	})
	mux.Handle("/", http.HandlerFunc(func(writer http.ResponseWriter, request *http.Request) {
		if !server.health.Ready() {
			http.Error(writer, "native proxy is not ready", http.StatusServiceUnavailable)

			return
		}
		server.gatewayHandler.ServeHTTP(writer, request)
	}))
	httpServer := &http.Server{Handler: mux, ReadHeaderTimeout: 5 * time.Second, IdleTimeout: server.config.IdleTimeout}

	server.health.SetReady(server.checkReadiness(ctx) == nil)
	defer server.health.SetReady(false)
	sessionContext, cancelSessions := context.WithCancel(context.Background())
	defer cancelSessions()
	postgresIdleListener := newIdleListener(postgresListener, server.config.IdleTimeout)
	mySQLIdleListener := newIdleListener(mysqlListener, server.config.IdleTimeout)
	errC := make(chan error, 4)
	var protocolWaitGroup sync.WaitGroup
	for _, runner := range []func() error{
		func() error { return server.handlePostgreSQL(sessionContext, postgresIdleListener) },
		func() error { return server.handleMySQL(sessionContext, mySQLIdleListener) },
		func() error { return httpServer.Serve(gatewayListener) },
	} {
		protocolWaitGroup.Add(1)
		go func(run func() error) {
			err := run()
			protocolWaitGroup.Done()
			select {
			case errC <- err:
			default:
			}
		}(runner)
	}
	readinessDone := make(chan struct{})
	go server.monitorReadiness(sessionContext, readinessDone)
	if subscriber, err := NewRevocationSubscriber(server.config.RedisURL, server.config.RevocationChannel, server.registry); err != nil {
		return err
	} else if subscriber != nil {
		go func() {
			err := subscriber.Run(sessionContext)
			select {
			case errC <- err:
			default:
			}
		}()
	}

	select {
	case <-ctx.Done():
		server.health.SetReady(false)
		shutdownContext, cancel := context.WithTimeout(context.Background(), server.config.DrainTimeout)
		defer cancel()
		_ = httpServer.Shutdown(shutdownContext)
		_ = postgresListener.Close()
		_ = mysqlListener.Close()
		drained := make(chan struct{})
		go func() {
			protocolWaitGroup.Wait()
			close(drained)
		}()
		select {
		case <-drained:
		case <-shutdownContext.Done():
			cancelSessions()
			postgresIdleListener.CloseConnections()
			mySQLIdleListener.CloseConnections()
			<-drained
		}
		cancelSessions()
		<-readinessDone

		return nil
	case err := <-errC:
		if errors.Is(err, http.ErrServerClosed) || errors.Is(err, net.ErrClosed) {
			return nil
		}

		return err
	}
}

func (server *Server) checkReadiness(ctx context.Context) error {
	if server.readinessCheck == nil {
		return nil
	}
	checkContext, cancel := context.WithTimeout(ctx, min(server.config.ReadinessInterval, 2*time.Second))
	defer cancel()

	return server.readinessCheck(checkContext)
}

func (server *Server) monitorReadiness(ctx context.Context, done chan<- struct{}) {
	defer close(done)
	ticker := time.NewTicker(server.config.ReadinessInterval)
	defer ticker.Stop()
	for {
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
			server.health.SetReady(server.checkReadiness(ctx) == nil)
		}
	}
}

func (server *Server) writeHealth(writer http.ResponseWriter, status int) {
	writer.Header().Set("Cache-Control", "no-store")
	writer.Header().Set("Content-Type", "application/json")
	writer.WriteHeader(status)
	_ = json.NewEncoder(writer).Encode(map[string]any{
		"status":             http.StatusText(status),
		"proxy_id":           server.config.ProxyID,
		"version":            version.Version,
		"instances":          1,
		"active_connections": server.registry.Count(),
	})
}

func rejectProtocolConnections(ctx context.Context, listener net.Listener) error {
	go func() {
		<-ctx.Done()
		_ = listener.Close()
	}()
	for {
		connection, err := listener.Accept()
		if err != nil {
			if errors.Is(err, net.ErrClosed) || ctx.Err() != nil {
				return nil
			}

			return err
		}
		_ = connection.Close()
	}
}
