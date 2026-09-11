package postgres

import (
	"bufio"
	"context"
	"errors"
	"fmt"
	"net"
	"strings"
	"time"

	"github.com/adiwidia-dev/crucible-db/native/internal/control"
	"github.com/adiwidia-dev/crucible-db/native/internal/proxy"
	"github.com/adiwidia-dev/crucible-db/native/internal/tunnel"
	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgconn"
	"github.com/jackc/pgx/v5/pgproto3"
)

func (server *Server) handleSession(ctx context.Context, connection net.Conn, reader *bufio.Reader, metadata tunnel.ConnectionMetadata) error {
	backend := pgproto3.NewBackend(reader, connection)
	backend.SetMaxBodyLen(maxFrontendMessageBytes)
	startup, err := backend.ReceiveStartupMessage()
	if err != nil {
		return err
	}
	if _, ok := startup.(*pgproto3.SSLRequest); ok {
		if _, err := connection.Write([]byte("N")); err != nil {
			return err
		}
		startup, err = backend.ReceiveStartupMessage()
		if err != nil {
			return err
		}
	}
	if cancelRequest, ok := startup.(*pgproto3.CancelRequest); ok {
		server.cancellationRegistry().cancel(ctx, cancelRequest.ProcessID, cancelRequest.SecretKey)
		return nil
	}
	message, ok := startup.(*pgproto3.StartupMessage)
	if !ok {
		return errors.New("unsupported PostgreSQL startup request")
	}
	username := message.Parameters["user"]
	if username == "" || requestsReplication(message.Parameters) {
		return errors.New("authentication failed")
	}
	if err := authenticate(backend, username, metadata.ProtocolAuthenticationSecret); err != nil {
		return writeError(backend, "authentication failed")
	}
	secret := metadata.ProtocolAuthenticationSecret
	metadata.ProtocolAuthenticationSecret = ""
	admission, err := server.Control.AuthorizeConnection(ctx, control.ConnectionAuthorizationRequest{
		AuthAttemptID: metadata.AuthAttemptID, ProxyID: server.ProxyID, SyntheticUsername: username, SyntheticPassword: secret, Protocol: "postgresql",
	})
	secret = ""
	if err != nil {
		return writeError(backend, "authentication failed")
	}
	closeReason := "Native PostgreSQL connection failed before upstream authentication."
	defer func() {
		_ = server.Control.CloseConnection(context.Background(), admission.ConnectionID, closeReason)
	}()
	upstreamMaterial, err := server.Control.FetchUpstreamMaterial(ctx, admission.ConnectionID)
	if err != nil || !startupDatabaseAllowed(message.Parameters["database"], upstreamMaterial.Upstream.Database, username) {
		return writeError(backend, "database access denied")
	}
	applicationName := sanitizeApplicationName(message.Parameters["application_name"])
	upstream, err := connect(ctx, upstreamMaterial.Upstream, applicationName, admission.ReadOnly)
	if err != nil {
		return writeError(backend, "upstream unavailable")
	}
	defer upstream.Close(ctx)
	if err := server.Control.MarkConnectionAuthenticated(ctx, admission.ConnectionID, applicationName, sanitizeApplicationName(message.Parameters["client_version"])); err != nil {
		return writeError(backend, "authentication failed")
	}
	closeReason = "Native PostgreSQL client disconnected."
	if server.Registry != nil {
		if err := server.Registry.Reserve(proxy.ConnectionIdentity{ID: admission.ConnectionID, LeaseID: admission.LeaseID, UserID: admission.UserID}); err != nil {
			return writeError(backend, "connection limit reached")
		}
		defer server.Registry.Release(admission.ConnectionID)
	}
	sessionContext, cancelSession := context.WithCancel(ctx)
	defer cancelSession()
	if server.Registry != nil {
		server.Registry.SetCloser(admission.ConnectionID, func() {
			cancelSession()
			_ = connection.Close()
		})
	}
	go server.heartbeat(sessionContext, cancelSession, connection, admission.ConnectionID)
	cancellationKey, unregisterCancellation, err := server.cancellationRegistry().register(upstream, nil, cancellationSecretLength(message.ProtocolVersion))
	if err != nil {
		return writeError(backend, "authentication failed")
	}
	defer unregisterCancellation()
	backend.Send(&pgproto3.AuthenticationOk{})
	backend.Send(&pgproto3.ParameterStatus{Name: "client_encoding", Value: "UTF8"})
	backend.Send(&pgproto3.ParameterStatus{Name: "standard_conforming_strings", Value: "on"})
	backend.Send(&pgproto3.ParameterStatus{Name: "server_version", Value: "16.0"})
	backend.Send(&pgproto3.BackendKeyData{ProcessID: cancellationKey.processID, SecretKey: cancellationKey.secret})
	backend.Send(&pgproto3.ReadyForQuery{TxStatus: 'I'})
	if err := backend.Flush(); err != nil {
		return err
	}
	extended := newExtendedState()
	defer extended.closeAll(context.Background(), server, admission.ConnectionID)
	txStatus := byte('I')
	for {
		frontend, err := backend.Receive()
		if err != nil {
			return err
		}
		switch typed := frontend.(type) {
		case *pgproto3.Terminate:
			return nil
		case *pgproto3.Flush:
			if err := backend.Flush(); err != nil {
				return err
			}
		case *pgproto3.Query:
			if extended.failed {
				continue
			}
			if err := server.handleSimpleQuery(sessionContext, backend, upstream, admission.ConnectionID, cancellationKey, typed.String, &txStatus); err != nil {
				return err
			}
		case *pgproto3.Parse, *pgproto3.Bind, *pgproto3.Execute, *pgproto3.Describe, *pgproto3.Close:
			if extended.failed {
				continue
			}
			if err := server.handleExtended(sessionContext, backend, upstream, admission.ConnectionID, cancellationKey, extended, typed, &txStatus); err != nil {
				return err
			}
		case *pgproto3.Sync:
			extended.failed = false
			backend.Send(&pgproto3.ReadyForQuery{TxStatus: txStatus})
			if err := backend.Flush(); err != nil {
				return err
			}
		default:
			if err := extended.writeError(backend, "unsupported PostgreSQL protocol message"); err != nil {
				return err
			}
		}
	}
}

func authenticate(backend *pgproto3.Backend, username, password string) error {
	server, err := newSCRAMServer(username, password)
	if err != nil {
		return err
	}
	conversation := server.NewConversation()
	backend.Send(&pgproto3.AuthenticationSASL{AuthMechanisms: []string{"SCRAM-SHA-256"}})
	if err := backend.Flush(); err != nil {
		return err
	}
	if err := backend.SetAuthType(pgproto3.AuthTypeSASL); err != nil {
		return err
	}
	first, err := backend.Receive()
	if err != nil {
		return err
	}
	initial, ok := first.(*pgproto3.SASLInitialResponse)
	if !ok || initial.AuthMechanism != "SCRAM-SHA-256" {
		return errors.New("invalid SASL mechanism")
	}
	challenge, err := conversation.Step(string(initial.Data))
	if err != nil {
		return err
	}
	backend.Send(&pgproto3.AuthenticationSASLContinue{Data: []byte(challenge)})
	if err := backend.Flush(); err != nil {
		return err
	}
	if err := backend.SetAuthType(pgproto3.AuthTypeSASLContinue); err != nil {
		return err
	}
	second, err := backend.Receive()
	if err != nil {
		return err
	}
	response, ok := second.(*pgproto3.SASLResponse)
	if !ok {
		return errors.New("invalid SASL response")
	}
	final, err := conversation.Step(string(response.Data))
	if err != nil || !conversation.Valid() {
		return errors.New("invalid credentials")
	}
	backend.Send(&pgproto3.AuthenticationSASLFinal{Data: []byte(final)})
	return backend.Flush()
}

func (server *Server) handleSimpleQuery(ctx context.Context, backend *pgproto3.Backend, upstream *pgx.Conn, connectionID string, cancellationKey cancellationKey, sql string, txStatus *byte) error {
	statements, err := splitPostgreSQLStatements(sql)
	if err != nil {
		return writeErrorWithStatus(backend, "statement is not allowed", *txStatus)
	}
	if len(statements) == 0 {
		backend.Send(&pgproto3.EmptyQueryResponse{})
	} else if len(statements) > 1 {
		if err := server.handleSimpleQueryBatch(ctx, backend, upstream, connectionID, cancellationKey, sql, statements, txStatus); err != nil {
			return err
		}
	} else {
		if _, err := server.handleSimpleStatement(ctx, backend, upstream, connectionID, cancellationKey, statements[0], txStatus); err != nil {
			return err
		}
	}

	backend.Send(&pgproto3.ReadyForQuery{TxStatus: *txStatus})

	return backend.Flush()
}

func (server *Server) handleSimpleQueryBatch(ctx context.Context, backend *pgproto3.Backend, upstream *pgx.Conn, connectionID string, cancellationKey cancellationKey, sql string, statements []string, txStatus *byte) error {
	for _, statementSQL := range statements {
		decision, err := server.Control.ValidateStatement(ctx, connectionID, control.StatementRequest{ProxyID: server.ProxyID, SQL: statementSQL, ProtocolCommand: "simple_query"})
		if err != nil || !decision.Allowed {
			if *txStatus == 'T' {
				*txStatus = 'E'
			}
			queueError(backend, "statement is not allowed")

			return nil
		}
	}

	authorizations := make([]control.StatementAuthorization, 0, len(statements))
	for _, statementSQL := range statements {
		authorization, err := server.Control.AuthorizeStatement(ctx, connectionID, control.StatementRequest{ProxyID: server.ProxyID, SQL: statementSQL, ProtocolCommand: "simple_query"})
		if err != nil {
			for _, authorized := range authorizations {
				server.completeSimpleStatement(connectionID, authorized.StatementID, time.Now(), false, 0, "batch authorization failed")
			}
			if *txStatus == 'T' {
				*txStatus = 'E'
			}
			queueError(backend, "statement is not allowed")

			return nil
		}
		authorizations = append(authorizations, authorization)
	}

	startedAt := time.Now()
	queryContext, cancelQuery := context.WithCancel(ctx)
	defer cancelQuery()
	endCancellation := server.cancellationRegistry().begin(cancellationKey, cancelQuery)
	defer endCancellation()
	results, queryErr := upstream.PgConn().Exec(queryContext, sql).ReadAll()

	completedResults := 0
	batchFailed := queryErr != nil
	for index, result := range results {
		if index >= len(authorizations) {
			batchFailed = true
			break
		}
		if result.Err != nil {
			server.completeSimpleStatement(connectionID, authorizations[index].StatementID, startedAt, false, 0, "upstream query failed")
			completedResults = index + 1
			batchFailed = true
			break
		}
		rowCount := queueSimpleQueryResult(backend, result)
		server.completeSimpleStatement(connectionID, authorizations[index].StatementID, startedAt, true, rowCount, "")
		updateTransactionStatus(statements[index], txStatus)
		completedResults = index + 1
	}

	if batchFailed || len(results) != len(authorizations) {
		for index := completedResults; index < len(authorizations); index++ {
			server.completeSimpleStatement(connectionID, authorizations[index].StatementID, startedAt, false, 0, "upstream query failed")
		}
		if *txStatus == 'T' {
			*txStatus = 'E'
		}
		queueError(backend, "query failed")
	}

	return nil
}

func (server *Server) completeSimpleStatement(connectionID string, statementID int, startedAt time.Time, succeeded bool, rowCount int64, message string) {
	_ = server.Control.CompleteStatement(context.Background(), connectionID, statementID, control.StatementOutcome{
		ProxyID: server.ProxyID, Succeeded: succeeded, RowCount: rowCount, DurationMS: time.Since(startedAt).Milliseconds(), ErrorMessage: message,
	})
}

func queueSimpleQueryResult(backend *pgproto3.Backend, result *pgconn.Result) int64 {
	if len(result.FieldDescriptions) > 0 {
		descriptions := make([]pgproto3.FieldDescription, len(result.FieldDescriptions))
		for index, field := range result.FieldDescriptions {
			descriptions[index] = pgproto3.FieldDescription{Name: []byte(field.Name), TableOID: field.TableOID, TableAttributeNumber: field.TableAttributeNumber, DataTypeOID: field.DataTypeOID, DataTypeSize: field.DataTypeSize, TypeModifier: field.TypeModifier, Format: pgproto3.TextFormat}
		}
		backend.Send(&pgproto3.RowDescription{Fields: descriptions})
	}
	for _, row := range result.Rows {
		backend.Send(&pgproto3.DataRow{Values: row})
	}
	if tag := result.CommandTag.String(); tag != "" {
		backend.Send(&pgproto3.CommandComplete{CommandTag: []byte(tag)})
	} else {
		backend.Send(&pgproto3.EmptyQueryResponse{})
	}

	return int64(len(result.Rows))
}

func (server *Server) handleSimpleStatement(ctx context.Context, backend *pgproto3.Backend, upstream *pgx.Conn, connectionID string, cancellationKey cancellationKey, sql string, txStatus *byte) (bool, error) {
	statement, err := server.Control.AuthorizeStatement(ctx, connectionID, control.StatementRequest{ProxyID: server.ProxyID, SQL: sql, ProtocolCommand: "simple_query"})
	if err != nil {
		if *txStatus == 'T' {
			*txStatus = 'E'
		}
		queueError(backend, "statement is not allowed")

		return false, nil
	}
	startedAt := time.Now()
	queryContext, cancelQuery := context.WithCancel(ctx)
	defer cancelQuery()
	endCancellation := server.cancellationRegistry().begin(cancellationKey, cancelQuery)
	defer endCancellation()
	completed := func(succeeded bool, rowCount int64, message string) {
		server.completeSimpleStatement(connectionID, statement.StatementID, startedAt, succeeded, rowCount, message)
	}
	rows, err := upstream.Query(queryContext, sql)
	if err != nil {
		completed(false, 0, "upstream query failed")
		if *txStatus == 'T' {
			*txStatus = 'E'
		}
		queueError(backend, "query failed")

		return false, nil
	}
	defer rows.Close()
	fields := rows.FieldDescriptions()
	if len(fields) > 0 {
		descriptions := make([]pgproto3.FieldDescription, len(fields))
		for index, field := range fields {
			descriptions[index] = pgproto3.FieldDescription{Name: []byte(field.Name), TableOID: field.TableOID, TableAttributeNumber: field.TableAttributeNumber, DataTypeOID: field.DataTypeOID, DataTypeSize: field.DataTypeSize, TypeModifier: field.TypeModifier, Format: pgproto3.TextFormat}
		}
		backend.Send(&pgproto3.RowDescription{Fields: descriptions})
	}
	rowCount := int64(0)
	for rows.Next() {
		backend.Send(&pgproto3.DataRow{Values: rows.RawValues()})
		rowCount++
	}
	if rows.Err() != nil {
		completed(false, rowCount, "upstream query failed")
		if *txStatus == 'T' {
			*txStatus = 'E'
		}
		queueError(backend, "query failed")

		return false, nil
	}
	tag := rows.CommandTag().String()
	if tag == "" {
		tag = fmt.Sprintf("SELECT %d", rowCount)
	}
	backend.Send(&pgproto3.CommandComplete{CommandTag: []byte(tag)})
	updateTransactionStatus(sql, txStatus)
	completed(true, rowCount, "")

	return true, nil
}

func writeError(backend *pgproto3.Backend, message string) error {
	return writeErrorWithStatus(backend, message, 'I')
}

func writeErrorWithStatus(backend *pgproto3.Backend, message string, txStatus byte) error {
	queueError(backend, message)
	backend.Send(&pgproto3.ReadyForQuery{TxStatus: txStatus})
	return backend.Flush()
}

func queueError(backend *pgproto3.Backend, message string) {
	backend.Send(&pgproto3.ErrorResponse{Severity: "ERROR", Code: "XX000", Message: message})
}

func sanitizeApplicationName(value string) string {
	value = strings.Map(func(character rune) rune {
		if character >= 32 && character <= 126 {
			return character
		}

		return -1
	}, value)
	return strings.TrimSpace(value)[:min(len(strings.TrimSpace(value)), 64)]
}

func updateTransactionStatus(sql string, txStatus *byte) {
	statement := strings.ToUpper(strings.TrimSpace(strings.TrimSuffix(sql, ";")))
	switch {
	case strings.HasPrefix(statement, "BEGIN"), strings.HasPrefix(statement, "START TRANSACTION"):
		*txStatus = 'T'
	case strings.HasPrefix(statement, "COMMIT"), strings.HasPrefix(statement, "ROLLBACK"):
		*txStatus = 'I'
	}
}

func requestsReplication(parameters map[string]string) bool {
	value := strings.TrimSpace(strings.ToLower(parameters["replication"]))

	return value != "" && value != "false" && value != "0" && value != "off"
}

func startupDatabaseAllowed(requested, approved, syntheticUsername string) bool {
	return requested == "" || requested == approved || requested == syntheticUsername
}
