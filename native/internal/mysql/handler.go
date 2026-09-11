package mysql

import (
	"context"
	"errors"
	"fmt"
	"strings"
	"sync"
	"time"

	"github.com/adiwidia-dev/crucible-db/native/internal/control"
	"github.com/go-mysql-org/go-mysql/client"
	mysqlproto "github.com/go-mysql-org/go-mysql/mysql"
	"github.com/go-mysql-org/go-mysql/server"
)

const maxPreparedStatements = 256
const maxFrontendMessageBytes = 10 << 20

type preparedStatement struct {
	query          string
	parameterCount int
}

type queryHandler struct {
	server.EmptyHandler
	ctx                             context.Context
	control                         ControlClient
	proxyID, connectionID, database string
	upstream                        control.UpstreamConfiguration
	upstreamConnection              *client.Conn
	clientConnection                *server.Conn
	requestedDatabase               string
	prepared                        map[*preparedStatement]struct{}
	releaseRegistry                 func()
	mu                              sync.Mutex
}

func (handler *queryHandler) UseDB(database string) error {
	if handler.database == "" {
		handler.requestedDatabase = database
		return nil
	}
	if !sameDatabase(database, handler.database) {
		return errors.New("database access denied")
	}
	return nil
}

func (handler *queryHandler) HandleQuery(query string) (*mysqlproto.Result, error) {
	if handler.connectionID == "" || len(query) > maxFrontendMessageBytes {
		return nil, errors.New("statement is not allowed")
	}
	if isUnsafeMySQLQuery(query) {
		return nil, errors.New("statement is not allowed")
	}
	statement, err := handler.control.AuthorizeStatement(handler.ctx, handler.connectionID, control.StatementRequest{ProxyID: handler.proxyID, SQL: query, ProtocolCommand: "com_query"})
	if err != nil {
		return nil, errors.New("statement is not allowed")
	}
	startedAt := time.Now()
	if isShowDatabases(query) {
		result, resultErr := mysqlproto.BuildSimpleTextResultset([]string{"Database"}, [][]any{{handler.database}})
		handler.complete(statement.StatementID, startedAt, resultErr == nil, 1, resultErr)
		if resultErr != nil {
			return nil, errors.New("query failed")
		}
		return mysqlproto.NewResult(result), nil
	}
	result, err := handler.execute(query)
	handler.complete(statement.StatementID, startedAt, err == nil, affectedRows(result), err)
	if err != nil {
		return nil, errors.New("query failed")
	}
	handler.updateStatus(query)
	return result, nil
}

func (handler *queryHandler) HandleStmtPrepare(query string) (int, int, any, error) {
	if handler.connectionID == "" || len(query) > maxFrontendMessageBytes || isUnsafeMySQLQuery(query) {
		return 0, 0, nil, errors.New("statement is not allowed")
	}
	parameterCount := countMySQLParameters(query)
	if parameterCount > maxPreparedStatements {
		return 0, 0, nil, errors.New("too many prepared statement parameters")
	}
	handler.mu.Lock()
	defer handler.mu.Unlock()
	if len(handler.prepared) >= maxPreparedStatements {
		return 0, 0, nil, errors.New("too many prepared statements")
	}
	decision, err := handler.control.ValidateStatement(handler.ctx, handler.connectionID, control.StatementRequest{ProxyID: handler.proxyID, SQL: query, ProtocolCommand: "com_stmt_prepare", ParameterCount: parameterCount})
	if err != nil || !decision.Allowed {
		return 0, 0, nil, errors.New("statement is not allowed")
	}
	statement := &preparedStatement{query: query, parameterCount: parameterCount}
	if handler.prepared == nil {
		handler.prepared = make(map[*preparedStatement]struct{})
	}
	handler.prepared[statement] = struct{}{}
	return parameterCount, 0, statement, nil
}

func (handler *queryHandler) HandleStmtExecute(statementContext any, query string, args []any) (*mysqlproto.Result, error) {
	prepared, ok := statementContext.(*preparedStatement)
	if !ok || handler.connectionID == "" || len(args) != prepared.parameterCount || len(args) > maxPreparedStatements || parameterBytes(args) > maxFrontendMessageBytes {
		return nil, errors.New("prepared statement is not allowed")
	}
	statement, err := handler.control.AuthorizeStatement(handler.ctx, handler.connectionID, control.StatementRequest{ProxyID: handler.proxyID, SQL: prepared.query, ProtocolCommand: "com_stmt_execute", ParameterCount: len(args)})
	if err != nil {
		return nil, errors.New("statement is not allowed")
	}
	startedAt := time.Now()
	result, err := handler.execute(prepared.query, args...)
	handler.complete(statement.StatementID, startedAt, err == nil, affectedRows(result), err)
	if err != nil {
		return nil, errors.New("query failed")
	}
	handler.updateStatus(prepared.query)
	return result, nil
}

func (handler *queryHandler) HandleStmtClose(statementContext any) error {
	statement, ok := statementContext.(*preparedStatement)
	if !ok {
		return errors.New("invalid prepared statement")
	}
	handler.mu.Lock()
	delete(handler.prepared, statement)
	handler.mu.Unlock()
	return nil
}

func (handler *queryHandler) HandleOtherCommand(command byte, _ []byte) error {
	return fmt.Errorf("MySQL command %d is not supported in Native Client Access", command)
}

func (handler *queryHandler) complete(statementID int, startedAt time.Time, succeeded bool, rowCount int64, executionError error) {
	outcome := control.StatementOutcome{ProxyID: handler.proxyID, Succeeded: succeeded, RowCount: rowCount, DurationMS: time.Since(startedAt).Milliseconds()}
	if executionError != nil {
		outcome.ErrorMessage = "upstream query failed"
	}
	_ = handler.control.CompleteStatement(context.Background(), handler.connectionID, statementID, outcome)
}

func (handler *queryHandler) execute(query string, args ...any) (*mysqlproto.Result, error) {
	handler.mu.Lock()
	defer handler.mu.Unlock()
	if handler.upstreamConnection == nil {
		return nil, errors.New("upstream unavailable")
	}
	return handler.upstreamConnection.Execute(query, args...)
}

func (handler *queryHandler) close() {
	handler.mu.Lock()
	defer handler.mu.Unlock()
	if handler.upstreamConnection == nil {
		return
	}
	_, _ = handler.upstreamConnection.Execute("ROLLBACK")
	_ = handler.upstreamConnection.Close()
	handler.upstreamConnection = nil
	if handler.connectionID != "" {
		_ = handler.control.CloseConnection(context.Background(), handler.connectionID, "Native MySQL client disconnected.")
	}
	if handler.releaseRegistry != nil {
		handler.releaseRegistry()
		handler.releaseRegistry = nil
	}
}

func (handler *queryHandler) updateStatus(query string) {
	if handler.clientConnection == nil {
		return
	}
	statement := strings.ToUpper(strings.TrimSpace(strings.TrimSuffix(query, ";")))
	switch {
	case strings.HasPrefix(statement, "BEGIN"), strings.HasPrefix(statement, "START TRANSACTION"):
		handler.clientConnection.SetInTransaction()
	case strings.HasPrefix(statement, "COMMIT"), strings.HasPrefix(statement, "ROLLBACK"):
		handler.clientConnection.ClearInTransaction()
	case strings.HasPrefix(statement, "SET") && strings.Contains(statement, "AUTOCOMMIT"):
		if strings.Contains(statement, "= 0") || strings.Contains(statement, "=0") {
			handler.clientConnection.UnsetStatus(mysqlproto.SERVER_STATUS_AUTOCOMMIT)
		} else {
			handler.clientConnection.SetStatus(mysqlproto.SERVER_STATUS_AUTOCOMMIT)
		}
	}
}

func sameDatabase(requested, expected string) bool {
	return strings.EqualFold(strings.Trim(strings.TrimSpace(requested), "`"), expected)
}

func isShowDatabases(query string) bool {
	statement := strings.TrimSpace(strings.TrimSuffix(query, ";"))
	return strings.EqualFold(statement, "show databases") || strings.EqualFold(statement, "show schemas")
}

func isUnsafeMySQLQuery(query string) bool {
	statement := strings.ToUpper(strings.TrimSpace(query))
	return strings.Contains(statement, "LOAD DATA") || strings.Contains(statement, "LOCAL INFILE") || strings.Contains(statement, "BINLOG") || strings.Contains(statement, "CHANGE USER") || strings.Contains(statement, "/*!") || strings.Contains(statement, "/*+")
}

func affectedRows(result *mysqlproto.Result) int64 {
	if result == nil {
		return 0
	}
	if result.Resultset != nil {
		if result.Resultset.RowNumber() > 0 || len(result.Resultset.RowDatas) == 0 {
			return int64(result.Resultset.RowNumber())
		}

		return int64(len(result.Resultset.RowDatas))
	}
	return int64(result.AffectedRows)
}

func parameterBytes(args []any) int {
	bytes := 0
	for _, argument := range args {
		if value, ok := argument.([]byte); ok {
			bytes += len(value)
		}
	}

	return bytes
}

func countMySQLParameters(sql string) int {
	count := 0
	var quote byte
	for index := 0; index < len(sql); index++ {
		character := sql[index]
		if quote != 0 {
			if character == '\\' {
				index++
			} else if character == quote {
				quote = 0
			}
			continue
		}
		if character == '\'' || character == '"' || character == '`' {
			quote = character
			continue
		}
		if character == '?' {
			count++
		}
	}
	return count
}
