package postgres

import (
	"context"
	"fmt"
	"time"

	"github.com/adiwidia-dev/crucible-db/native/internal/control"
	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgproto3"
)

const maxPreparedStatements = 256

type preparedStatement struct {
	sql               string
	upstreamName      string
	parameterOIDs     []uint32
	fieldDescriptions []pgproto3.FieldDescription
}

type portal struct {
	statement       preparedStatement
	parameters      []any
	rows            pgx.Rows
	pending         [][][]byte
	completed       bool
	commandTag      string
	statementID     int
	startedAt       time.Time
	rowCount        int64
	cancel          context.CancelFunc
	endCancellation func()
}

type extendedState struct {
	statements map[string]preparedStatement
	portals    map[string]*portal
	nextID     uint64
	failed     bool
}

func newExtendedState() *extendedState {
	return &extendedState{statements: map[string]preparedStatement{}, portals: map[string]*portal{}}
}

func (state *extendedState) writeError(backend *pgproto3.Backend, message string) error {
	state.failed = true
	backend.Send(&pgproto3.ErrorResponse{Severity: "ERROR", Code: "XX000", Message: message})
	return backend.Flush()
}

func (server *Server) handleExtended(ctx context.Context, backend *pgproto3.Backend, upstream *pgx.Conn, connectionID string, cancellationKey cancellationKey, state *extendedState, message pgproto3.FrontendMessage, txStatus *byte) error {
	switch typed := message.(type) {
	case *pgproto3.Parse:
		return server.parse(ctx, backend, upstream, connectionID, state, typed)
	case *pgproto3.Bind:
		return server.bind(ctx, backend, upstream, connectionID, state, typed)
	case *pgproto3.Execute:
		return server.executePrepared(ctx, backend, upstream, connectionID, cancellationKey, state, typed, txStatus)
	case *pgproto3.Describe:
		return state.describe(backend, typed)
	case *pgproto3.Close:
		return server.close(ctx, backend, upstream, connectionID, state, typed)
	default:
		return state.writeError(backend, "unsupported PostgreSQL extended protocol message")
	}
}

func (server *Server) parse(ctx context.Context, backend *pgproto3.Backend, upstream *pgx.Conn, connectionID string, state *extendedState, message *pgproto3.Parse) error {
	if len(message.ParameterOIDs) > maxPreparedStatements {
		return state.writeError(backend, "too many prepared statement parameters")
	}
	if _, exists := state.statements[message.Name]; !exists && len(state.statements) >= maxPreparedStatements {
		return state.writeError(backend, "too many prepared statements")
	}
	decision, err := server.Control.ValidateStatement(ctx, connectionID, control.StatementRequest{
		ProxyID: server.ProxyID, SQL: message.Query, ProtocolCommand: "parse", ParameterCount: len(message.ParameterOIDs),
	})
	if err != nil || !decision.Allowed {
		return state.writeError(backend, "statement is not allowed")
	}
	if previous, exists := state.statements[message.Name]; exists {
		server.closePortalsForStatement(context.Background(), connectionID, state, previous.upstreamName, "prepared statement was replaced")
		_ = upstream.Deallocate(ctx, previous.upstreamName)
	}
	state.nextID++
	upstreamName := fmt.Sprintf("crucible_%d", state.nextID)
	description, err := upstream.Prepare(ctx, upstreamName, message.Query)
	if err != nil {
		return state.writeError(backend, "statement could not be prepared")
	}
	parameterOIDs := description.ParamOIDs
	if len(message.ParameterOIDs) > 0 {
		parameterOIDs = message.ParameterOIDs
	}
	fields := make([]pgproto3.FieldDescription, len(description.Fields))
	for index, field := range description.Fields {
		fields[index] = pgproto3.FieldDescription{
			Name: []byte(field.Name), TableOID: field.TableOID, TableAttributeNumber: field.TableAttributeNumber,
			DataTypeOID: field.DataTypeOID, DataTypeSize: field.DataTypeSize, TypeModifier: field.TypeModifier, Format: pgproto3.TextFormat,
		}
	}
	state.statements[message.Name] = preparedStatement{sql: message.Query, upstreamName: upstreamName, parameterOIDs: parameterOIDs, fieldDescriptions: fields}
	backend.Send(&pgproto3.ParseComplete{})
	return backend.Flush()
}

func (server *Server) bind(ctx context.Context, backend *pgproto3.Backend, upstream *pgx.Conn, connectionID string, state *extendedState, message *pgproto3.Bind) error {
	statement, found := state.statements[message.PreparedStatement]
	if !found {
		return state.writeError(backend, "unknown prepared statement")
	}
	if len(message.Parameters) != len(statement.parameterOIDs) {
		return state.writeError(backend, "prepared statement parameter count does not match")
	}
	parameterFormats, err := expandedFormats(message.ParameterFormatCodes, len(message.Parameters))
	if err != nil {
		return state.writeError(backend, "invalid prepared statement parameter formats")
	}
	resultFormats, err := expandedFormats(message.ResultFormatCodes, len(statement.fieldDescriptions))
	if err != nil {
		return state.writeError(backend, "invalid prepared statement result formats")
	}
	parameters := make([]any, len(message.Parameters))
	for index, value := range message.Parameters {
		if value == nil {
			continue
		}
		if err := upstream.TypeMap().Scan(statement.parameterOIDs[index], parameterFormats[index], value, &parameters[index]); err != nil {
			return state.writeError(backend, "prepared statement parameter could not be decoded")
		}
	}
	if _, exists := state.portals[message.DestinationPortal]; !exists && len(state.portals) >= maxPreparedStatements {
		return state.writeError(backend, "too many prepared portals")
	}
	if previous, exists := state.portals[message.DestinationPortal]; exists {
		server.finishPortal(context.Background(), connectionID, previous, false, "portal was replaced")
	}
	fields := append([]pgproto3.FieldDescription(nil), statement.fieldDescriptions...)
	for index := range fields {
		fields[index].Format = resultFormats[index]
	}
	statement.fieldDescriptions = fields
	state.portals[message.DestinationPortal] = &portal{statement: statement, parameters: parameters}
	backend.Send(&pgproto3.BindComplete{})
	return backend.Flush()
}

func (state *extendedState) describe(backend *pgproto3.Backend, message *pgproto3.Describe) error {
	var statement preparedStatement
	switch message.ObjectType {
	case 'S':
		var found bool
		statement, found = state.statements[message.Name]
		if !found {
			return state.writeError(backend, "unknown prepared statement")
		}
	case 'P':
		portal, found := state.portals[message.Name]
		if !found {
			return state.writeError(backend, "unknown portal")
		}
		statement = portal.statement
		if len(statement.fieldDescriptions) == 0 {
			backend.Send(&pgproto3.NoData{})
		} else {
			backend.Send(&pgproto3.RowDescription{Fields: statement.fieldDescriptions})
		}

		return backend.Flush()
	default:
		return state.writeError(backend, "invalid Describe target")
	}
	backend.Send(&pgproto3.ParameterDescription{ParameterOIDs: statement.parameterOIDs})
	if len(statement.fieldDescriptions) == 0 {
		backend.Send(&pgproto3.NoData{})
	} else {
		backend.Send(&pgproto3.RowDescription{Fields: statement.fieldDescriptions})
	}
	return backend.Flush()
}

func (server *Server) close(ctx context.Context, backend *pgproto3.Backend, upstream *pgx.Conn, connectionID string, state *extendedState, message *pgproto3.Close) error {
	switch message.ObjectType {
	case 'S':
		if statement, found := state.statements[message.Name]; found {
			server.closePortalsForStatement(context.Background(), connectionID, state, statement.upstreamName, "prepared statement was closed")
			_ = upstream.Deallocate(ctx, statement.upstreamName)
			delete(state.statements, message.Name)
		}
	case 'P':
		if portal, found := state.portals[message.Name]; found {
			server.finishPortal(context.Background(), connectionID, portal, false, "portal was closed")
		}
		delete(state.portals, message.Name)
	default:
		return state.writeError(backend, "invalid Close target")
	}
	backend.Send(&pgproto3.CloseComplete{})
	return backend.Flush()
}

func (server *Server) executePrepared(ctx context.Context, backend *pgproto3.Backend, upstream *pgx.Conn, connectionID string, cancellationKey cancellationKey, state *extendedState, message *pgproto3.Execute, txStatus *byte) error {
	portal, found := state.portals[message.Portal]
	if !found {
		return state.writeError(backend, "unknown portal")
	}
	if portal.completed {
		backend.Send(&pgproto3.CommandComplete{CommandTag: []byte(portal.commandTag)})
		return backend.Flush()
	}
	if portal.rows == nil {
		statement, err := server.Control.AuthorizeStatement(ctx, connectionID, control.StatementRequest{
			ProxyID: server.ProxyID, SQL: portal.statement.sql, ProtocolCommand: "extended_execute", ParameterCount: len(portal.parameters),
		})
		if err != nil {
			return state.writeError(backend, "statement is not allowed")
		}
		portal.statementID = statement.StatementID
		portal.startedAt = time.Now()
		queryContext, cancelQuery := context.WithCancel(ctx)
		portal.cancel = cancelQuery
		portal.endCancellation = server.cancellationRegistry().begin(cancellationKey, cancelQuery)
		queryArguments := make([]any, 0, len(portal.parameters)+1)
		queryArguments = append(queryArguments, pgx.QueryResultFormats(fieldFormats(portal.statement.fieldDescriptions)))
		queryArguments = append(queryArguments, portal.parameters...)
		rows, err := upstream.Query(queryContext, portal.statement.upstreamName, queryArguments...)
		if err != nil {
			server.finishPortal(context.Background(), connectionID, portal, false, "upstream query failed")
			if *txStatus == 'T' {
				*txStatus = 'E'
			}
			return state.writeError(backend, "query failed")
		}
		portal.rows = rows
	}
	rowCount, suspended := sendPortalRows(backend, portal, message.MaxRows)
	portal.rowCount += rowCount
	if suspended {
		backend.Send(&pgproto3.PortalSuspended{})
		return backend.Flush()
	}
	if err := portal.rows.Err(); err != nil {
		server.finishPortal(context.Background(), connectionID, portal, false, "upstream query failed")
		if *txStatus == 'T' {
			*txStatus = 'E'
		}
		return state.writeError(backend, "query failed")
	}
	portal.commandTag = portal.rows.CommandTag().String()
	if portal.commandTag == "" {
		portal.commandTag = fmt.Sprintf("SELECT %d", rowCount)
	}
	portal.rows.Close()
	portal.rows = nil
	portal.completed = true
	server.finishPortal(context.Background(), connectionID, portal, true, "")
	updateTransactionStatus(portal.statement.sql, txStatus)
	backend.Send(&pgproto3.CommandComplete{CommandTag: []byte(portal.commandTag)})
	return backend.Flush()
}

func (server *Server) finishPortal(ctx context.Context, connectionID string, portal *portal, succeeded bool, message string) {
	if portal.rows != nil {
		portal.rows.Close()
		portal.rows = nil
	}
	if portal.endCancellation != nil {
		portal.endCancellation()
		portal.endCancellation = nil
	}
	if portal.cancel != nil {
		portal.cancel()
		portal.cancel = nil
	}
	if portal.statementID == 0 {
		return
	}
	_ = server.Control.CompleteStatement(ctx, connectionID, portal.statementID, control.StatementOutcome{
		ProxyID: server.ProxyID, Succeeded: succeeded, RowCount: portal.rowCount,
		DurationMS: time.Since(portal.startedAt).Milliseconds(), ErrorMessage: message,
	})
	portal.statementID = 0
}

func (server *Server) closePortalsForStatement(ctx context.Context, connectionID string, state *extendedState, upstreamName, reason string) {
	for name, portal := range state.portals {
		if portal.statement.upstreamName == upstreamName {
			server.finishPortal(ctx, connectionID, portal, false, reason)
			delete(state.portals, name)
		}
	}
}

func (state *extendedState) closeAll(ctx context.Context, server *Server, connectionID string) {
	for name, portal := range state.portals {
		server.finishPortal(ctx, connectionID, portal, false, "client disconnected")
		delete(state.portals, name)
	}
}

func sendPortalRows(backend *pgproto3.Backend, portal *portal, maxRows uint32) (int64, bool) {
	var count int64
	for len(portal.pending) > 0 {
		backend.Send(&pgproto3.DataRow{Values: portal.pending[0]})
		portal.pending = portal.pending[1:]
		count++
		if maxRows > 0 && count >= int64(maxRows) {
			return count, true
		}
	}
	for maxRows == 0 || count < int64(maxRows) {
		if !portal.rows.Next() {
			return count, false
		}
		backend.Send(&pgproto3.DataRow{Values: portal.rows.RawValues()})
		count++
	}
	if portal.rows.Next() {
		values := portal.rows.RawValues()
		pending := make([][]byte, len(values))
		for index, value := range values {
			pending[index] = append([]byte(nil), value...)
		}
		portal.pending = [][][]byte{pending}
		return count, true
	}
	return count, false
}

func expandedFormats(formats []int16, count int) ([]int16, error) {
	expanded := make([]int16, count)
	switch len(formats) {
	case 0:
		return expanded, nil
	case 1:
		for index := range expanded {
			expanded[index] = formats[0]
		}
	case count:
		copy(expanded, formats)
	default:
		return nil, fmt.Errorf("format count must be zero, one, or match the value count")
	}
	for _, format := range expanded {
		if format != pgproto3.TextFormat && format != pgproto3.BinaryFormat {
			return nil, fmt.Errorf("unsupported format code %d", format)
		}
	}

	return expanded, nil
}

func fieldFormats(fields []pgproto3.FieldDescription) []int16 {
	formats := make([]int16, len(fields))
	for index, field := range fields {
		formats[index] = field.Format
	}

	return formats
}
