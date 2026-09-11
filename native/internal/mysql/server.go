package mysql

import (
	"context"
	"errors"
	"net"
	"sync"

	"github.com/adiwidia-dev/crucible-db/native/internal/control"
	"github.com/adiwidia-dev/crucible-db/native/internal/proxy"
	"github.com/adiwidia-dev/crucible-db/native/internal/tunnel"
	mysqlproto "github.com/go-mysql-org/go-mysql/mysql"
	mysqlserver "github.com/go-mysql-org/go-mysql/server"
)

type ControlClient interface {
	AuthorizeConnection(context.Context, control.ConnectionAuthorizationRequest) (control.ConnectionAuthorizationResponse, error)
	FetchUpstreamMaterial(context.Context, string) (control.ConnectionUpstreamMaterialResponse, error)
	MarkConnectionAuthenticated(context.Context, string, string, string) error
	HeartbeatConnection(context.Context, string) (control.ConnectionHeartbeatResponse, error)
	CloseConnection(context.Context, string, string) error
	ValidateStatement(context.Context, string, control.StatementRequest) (control.StatementDecision, error)
	AuthorizeStatement(context.Context, string, control.StatementRequest) (control.StatementAuthorization, error)
	CompleteStatement(context.Context, string, int, control.StatementOutcome) error
}

type Server struct {
	Control  ControlClient
	ProxyID  string
	Registry *proxy.ConnectionRegistry

	once           sync.Once
	protocolServer *mysqlserver.Server
	protocolErr    error
}

func (server *Server) Serve(ctx context.Context, listener net.Listener) error {
	if server.Control == nil || server.ProxyID == "" {
		return errors.New("mysql native proxy is not configured")
	}
	go func() { <-ctx.Done(); _ = listener.Close() }()
	var sessions sync.WaitGroup
	defer sessions.Wait()
	for {
		connection, err := listener.Accept()
		if err != nil {
			if errors.Is(err, net.ErrClosed) || ctx.Err() != nil {
				return nil
			}
			return err
		}
		sessions.Add(1)
		go func() {
			defer sessions.Done()
			_ = server.handle(ctx, connection)
		}()
	}
}

func (server *Server) handle(ctx context.Context, connection net.Conn) error {
	defer connection.Close()
	metadata, reader, err := tunnel.ReadConnectionMetadata(connection)
	if err != nil || metadata.Protocol != "mysql" {
		return errors.New("invalid native MySQL connection")
	}
	wrapped := &readerConnection{Conn: connection, reader: reader}
	sessionContext, cancelSession := context.WithCancel(ctx)
	defer cancelSession()
	handler := &queryHandler{ctx: sessionContext, control: server.Control, proxyID: server.ProxyID}
	defer handler.close()
	authentication := &authHandler{InMemoryAuthenticationHandler: server.NewAuthentication(metadata.ProtocolAuthenticationSecret), ctx: sessionContext, cancel: cancelSession, metadata: metadata, control: server.Control, proxyID: server.ProxyID, registry: server.Registry, handler: handler, clientSocket: connection}
	connectionServer, err := server.server()
	if err != nil {
		return err
	}
	serverConnection, err := connectionServer.NewCustomizedConn(wrapped, authentication, handler)
	if err != nil {
		return err
	}
	defer func() {
		if !serverConnection.Closed() {
			serverConnection.Close()
		}
	}()
	for !serverConnection.Closed() {
		if err := serverConnection.HandleCommand(); err != nil {
			return err
		}
	}
	return nil
}

func (server *Server) server() (*mysqlserver.Server, error) {
	server.once.Do(func() {
		server.protocolServer = mysqlserver.NewServer("8.4.0-crucible", mysqlproto.DEFAULT_COLLATION_ID, mysqlproto.AUTH_NATIVE_PASSWORD, nil, nil)
		// The WebSocket tunnel is already encrypted. Do not advertise CLIENT_SSL on
		// this inner loopback protocol, nor any multi-result capability that can
		// hide statements from the per-statement authorization path.
		server.protocolErr = server.protocolServer.UnsetCapability(mysqlproto.CLIENT_MULTI_RESULTS | mysqlproto.CLIENT_PS_MULTI_RESULTS)
	})

	return server.protocolServer, server.protocolErr
}

func (*Server) NewAuthentication(password string) *mysqlserver.InMemoryAuthenticationHandler {
	handler := mysqlserver.NewInMemoryAuthenticationHandler(mysqlproto.AUTH_NATIVE_PASSWORD)
	_ = handler.AddUser("", password, mysqlproto.AUTH_NATIVE_PASSWORD)
	return handler
}

type readerConnection struct {
	net.Conn
	reader interface{ Read([]byte) (int, error) }
}

func (connection *readerConnection) Read(buffer []byte) (int, error) {
	return connection.reader.Read(buffer)
}
