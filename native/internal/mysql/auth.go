package mysql

import (
	"context"
	"errors"
	"net"
	"strings"
	"time"

	"github.com/adiwidia-dev/crucible-db/native/internal/control"
	"github.com/adiwidia-dev/crucible-db/native/internal/proxy"
	"github.com/adiwidia-dev/crucible-db/native/internal/tunnel"
	mysqlproto "github.com/go-mysql-org/go-mysql/mysql"
	"github.com/go-mysql-org/go-mysql/server"
)

type authHandler struct {
	*server.InMemoryAuthenticationHandler
	ctx          context.Context
	cancel       context.CancelFunc
	metadata     tunnel.ConnectionMetadata
	control      ControlClient
	proxyID      string
	registry     *proxy.ConnectionRegistry
	handler      *queryHandler
	clientSocket net.Conn
}

func (handler *authHandler) GetCredential(username string) (server.Credential, bool, error) {
	credential, _, err := handler.InMemoryAuthenticationHandler.GetCredential("")
	if err != nil {
		return server.Credential{}, false, err
	}
	return credential, true, nil
}

func (handler *authHandler) OnAuthSuccess(connection *server.Conn) error {
	admission, err := handler.control.AuthorizeConnection(handler.ctx, control.ConnectionAuthorizationRequest{AuthAttemptID: handler.metadata.AuthAttemptID, ProxyID: handler.proxyID, SyntheticUsername: connection.GetUser(), SyntheticPassword: handler.metadata.ProtocolAuthenticationSecret, Protocol: "mysql"})
	handler.metadata.ProtocolAuthenticationSecret = ""
	if err != nil {
		return errors.New("access denied")
	}
	closeReason := "Native MySQL connection failed before upstream authentication."
	reservationHandedOff := false
	defer func() {
		if !reservationHandedOff {
			_ = handler.control.CloseConnection(context.Background(), admission.ConnectionID, closeReason)
		}
	}()
	upstreamMaterial, err := handler.control.FetchUpstreamMaterial(handler.ctx, admission.ConnectionID)
	if err != nil {
		return errors.New("access denied")
	}
	if handler.handler.requestedDatabase != "" && !sameDatabase(handler.handler.requestedDatabase, upstreamMaterial.Upstream.Database) {
		closeReason = "Native MySQL connection requested an unauthorized database."
		return errors.New("access denied")
	}
	upstream, err := open(handler.ctx, upstreamMaterial.Upstream, admission.ReadOnly)
	if err != nil {
		return errors.New("access denied")
	}
	clientApplication, clientVersion := mySQLClientMetadata(connection)
	if err := handler.control.MarkConnectionAuthenticated(handler.ctx, admission.ConnectionID, clientApplication, clientVersion); err != nil {
		_ = upstream.Close()
		closeReason = "Native MySQL connection could not consume its reservation."
		return errors.New("access denied")
	}
	if handler.registry != nil {
		if err := handler.registry.Reserve(proxy.ConnectionIdentity{ID: admission.ConnectionID, LeaseID: admission.LeaseID, UserID: admission.UserID}); err != nil {
			_ = upstream.Close()
			closeReason = "Native MySQL connection exceeded the local safety limit."
			return errors.New("connection limit reached")
		}
		handler.registry.SetCloser(admission.ConnectionID, func() {
			handler.cancel()
			_ = handler.clientSocket.Close()
		})
		handler.handler.releaseRegistry = func() { handler.registry.Release(admission.ConnectionID) }
	}
	handler.handler.connectionID = admission.ConnectionID
	handler.handler.database = upstreamMaterial.Upstream.Database
	handler.handler.upstream = upstreamMaterial.Upstream
	handler.handler.upstreamConnection = upstream
	handler.handler.clientConnection = connection
	connection.SetStatus(mysqlproto.SERVER_STATUS_AUTOCOMMIT)
	reservationHandedOff = true
	go handler.heartbeat(admission.ConnectionID)
	return nil
}

func mySQLClientMetadata(connection *server.Conn) (string, string) {
	attributes := connection.Attributes()
	application := attributes["_client_name"]
	if application == "" {
		application = attributes["program_name"]
	}
	version := attributes["_client_version"]

	return sanitizeClientMetadata(application), sanitizeClientMetadata(version)
}

func sanitizeClientMetadata(value string) string {
	value = strings.Map(func(character rune) rune {
		if character >= 32 && character <= 126 {
			return character
		}

		return -1
	}, value)
	value = strings.TrimSpace(value)

	return value[:min(len(value), 128)]
}

func (handler *authHandler) heartbeat(connectionID string) {
	ticker := time.NewTicker(2 * time.Second)
	defer ticker.Stop()
	for {
		select {
		case <-handler.ctx.Done():
			return
		case <-ticker.C:
			decision, err := handler.control.HeartbeatConnection(handler.ctx, connectionID)
			if err != nil || !decision.Continue {
				handler.cancel()
				_ = handler.clientSocket.Close()

				return
			}
		}
	}
}
