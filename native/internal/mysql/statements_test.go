package mysql

import (
	"testing"

	mysqlproto "github.com/go-mysql-org/go-mysql/mysql"
)

func TestPreparedStatementParameterCounterIgnoresQuotedQuestionMarks(t *testing.T) {
	if actual := countMySQLParameters("select ?, '?', \"?\", `?` from users where id = ?"); actual != 2 {
		t.Fatalf("expected two bind parameters, got %d", actual)
	}
}

func TestNativeMySQLCommandGuardsRejectUnsafeQueries(t *testing.T) {
	for _, query := range []string{
		"LOAD DATA LOCAL INFILE '/tmp/input' INTO TABLE users",
		"BINLOG 'payload'",
		"CHANGE USER root",
	} {
		if !isUnsafeMySQLQuery(query) {
			t.Fatalf("expected %q to be rejected", query)
		}
	}
}

func TestNativeMySQLCommandGuardsFilterDatabaseDiscovery(t *testing.T) {
	if !isShowDatabases("SHOW DATABASES") || !isShowDatabases("show schemas;") {
		t.Fatal("expected database discovery commands to receive the lease-only result")
	}
	if isShowDatabases("SHOW TABLES") {
		t.Fatal("expected unrelated SHOW commands to remain policy-controlled")
	}
}

func TestPreparedStatementBinaryParametersRemainSupportedWithinTheSizeLimit(t *testing.T) {
	if parameterBytes([]any{[]byte("binary-value"), "plain value", []byte{0x01, 0x02}}) != 14 {
		t.Fatal("expected binary parameter bytes to be counted without rejecting binary values")
	}
}

func TestAffectedRowsReportsRowsReturnedBySelectResultSets(t *testing.T) {
	resultset, err := mysqlproto.BuildSimpleTextResultset([]string{"id"}, [][]any{{1}, {2}})
	if err != nil {
		t.Fatal(err)
	}
	if actual := affectedRows(&mysqlproto.Result{Resultset: resultset}); actual != 2 {
		t.Fatalf("expected two returned rows, got %d", actual)
	}
}
