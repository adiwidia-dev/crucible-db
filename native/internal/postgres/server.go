package postgres

import (
	"context"
	"errors"
	"net"
	"sync"

	"github.com/adiwidia-dev/crucible-db/native/internal/control"
	"github.com/adiwidia-dev/crucible-db/native/internal/proxy"
	"github.com/adiwidia-dev/crucible-db/native/internal/tunnel"
)

const maxFrontendMessageBytes = 10 << 20

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

	cancellationOnce sync.Once
	cancellations    *cancellationRegistry
}

func (server *Server) Serve(ctx context.Context, listener net.Listener) error {
	if server.Control == nil || server.ProxyID == "" {
		return errors.New("postgres native proxy is not configured")
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
			_ = server.Handle(ctx, connection)
		}()
	}
}

func (server *Server) Handle(ctx context.Context, connection net.Conn) error {
	defer connection.Close()
	metadata, reader, err := tunnel.ReadConnectionMetadata(connection)
	if err != nil {
		return err
	}
	if metadata.Protocol != "postgresql" {
		return errors.New("wrong native protocol listener")
	}
	return server.handleSession(ctx, connection, reader, metadata)
}

func (server *Server) cancellationRegistry() *cancellationRegistry {
	server.cancellationOnce.Do(func() {
		server.cancellations = newCancellationRegistry()
	})

	return server.cancellations
}
