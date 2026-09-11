//go:build integration

package integration

import (
	"context"
	"encoding/json"
	"net"
	"strings"
	"testing"
	"time"

	"github.com/go-mysql-org/go-mysql/client"
	mysqlproto "github.com/go-mysql-org/go-mysql/mysql"
	"github.com/redis/go-redis/v9"
)

func TestMySQLClientTraversesDeviceTokenTunnelPolicyAndProxy(t *testing.T) {
	harness := integrationHarness(t)
	document, token := harness.issueDeviceToken(t, mySQLDevice)
	address, stopTunnel := harness.startTunnel(t, document, token, mySQLLeaseID, "mysql", "integration-mysql-connection")
	defer stopTunnel()

	connection := connectMySQL(t, address)
	defer connection.Close()
	if err := connection.Ping(); err != nil {
		t.Fatalf("ping MySQL through native proxy: %v", err)
	}
	if strings.TrimSpace(connection.GetServerVersion()) == "" {
		t.Fatal("native MySQL proxy returned an empty server version")
	}

	result := executeMySQL(t, connection, "SELECT DATABASE(), count(*) FROM native_client_fixture")
	database := mySQLString(t, result, 0, 0)
	count := mySQLInt(t, result, 0, 1)
	if database != "crucible_target" || count != 2 {
		t.Fatalf("unexpected MySQL identity or fixture count: database=%q count=%d", database, count)
	}

	result = executeMySQL(t, connection, "SHOW DATABASES")
	if result.Resultset.RowNumber() != 1 || mySQLString(t, result, 0, 0) != "crucible_target" {
		t.Fatalf("SHOW DATABASES leaked databases outside the lease: rows=%d", result.Resultset.RowNumber())
	}
	for _, statement := range []string{
		"SHOW TABLES",
		"SHOW FULL COLUMNS FROM native_client_fixture FROM crucible_target",
		"SHOW INDEX FROM crucible_target.native_client_fixture",
		"SHOW CREATE TABLE crucible_target.native_client_fixture",
		"SHOW VARIABLES LIKE 'lower_case_table_names'",
		"SHOW SESSION STATUS LIKE 'Ssl_cipher'",
		"SHOW CHARACTER SET WHERE charset = 'utf8mb4'",
		"SHOW COLLATION",
	} {
		result = executeMySQL(t, connection, statement)
		if result.Resultset == nil || result.Resultset.RowNumber() == 0 {
			t.Fatalf("MySQL metadata statement returned no rows: %s", statement)
		}
	}
	for _, statement := range []string{
		"SET NAMES utf8mb4",
		"/* ApplicationName=DBeaver */ SET autocommit=0",
		"/* client=DBeaver */ /* java thread=main */ SET autocommit=1",
		"/* ApplicationName=DBeaver */ USE `crucible_target`",
	} {
		executeMySQL(t, connection, statement)
	}

	prepared, err := connection.Prepare("SELECT ? + ? AS total, OCTET_LENGTH(?) AS binary_length")
	if err != nil {
		t.Fatalf("prepare binary MySQL statement: %v", err)
	}
	if prepared.ParamNum() != 3 {
		t.Fatalf("unexpected MySQL prepared parameter count: %d", prepared.ParamNum())
	}
	result, err = prepared.Execute(int64(20), int64(22), []byte{0x00, 0x01, 0x02})
	if err != nil {
		t.Fatalf("execute binary MySQL statement: %v", err)
	}
	if total := mySQLInt(t, result, 0, 0); total != 42 {
		t.Fatalf("unexpected MySQL prepared total: %d", total)
	}
	if length := mySQLInt(t, result, 0, 1); length != 3 {
		t.Fatalf("unexpected MySQL prepared binary length: %d", length)
	}
	if err := prepared.Close(); err != nil {
		t.Fatalf("close binary MySQL statement: %v", err)
	}

	if err := connection.BeginTx(true, ""); err != nil {
		t.Fatalf("start read-only MySQL transaction: %v", err)
	}
	executeMySQL(t, connection, "SAVEPOINT qualified")
	result = executeMySQL(t, connection, "SELECT count(*) FROM native_client_fixture")
	if count := mySQLInt(t, result, 0, 0); count != 2 {
		t.Fatalf("unexpected MySQL transaction fixture count: %d", count)
	}
	executeMySQL(t, connection, "ROLLBACK TO SAVEPOINT qualified")
	executeMySQL(t, connection, "RELEASE SAVEPOINT qualified")
	if err := connection.Commit(); err != nil {
		t.Fatalf("commit read-only MySQL transaction: %v", err)
	}

	for _, statement := range []string{
		"START TRANSACTION READ WRITE",
		"SHOW TABLES FROM mysql",
		"SHOW GRANTS",
		"KILL QUERY 1",
	} {
		if _, err := connection.Execute(statement); err == nil {
			t.Fatalf("native MySQL policy unexpectedly allowed %s", statement)
		}
		assertMySQLConnectionUsable(t, connection)
	}
	if _, err := connection.Execute("SELECT * FROM definitely_missing_native_fixture"); err == nil {
		t.Fatal("MySQL upstream error probe unexpectedly succeeded")
	}
	assertMySQLConnectionUsable(t, connection)

	t.Run("mysql executable", func(t *testing.T) {
		host, port, err := net.SplitHostPort(address)
		if err != nil {
			t.Fatalf("parse MySQL loopback listener: %v", err)
		}
		image := environment("NATIVE_INTEGRATION_MYSQL_CLIENT_IMAGE", "")
		arguments := []string{
			"mysql", "--protocol=TCP", "--ssl-mode=DISABLED", "--host=" + host, "--port=" + port,
			"--user=crucible_native_mysql", "--database=crucible_target", "--batch", "--raw",
			"--skip-column-names", "--force",
		}
		script := `SELECT 'mysql-connected', DATABASE();
SHOW TABLES;
SHOW FULL COLUMNS FROM native_client_fixture FROM crucible_target;
SHOW INDEX FROM crucible_target.native_client_fixture;
SHOW CREATE TABLE crucible_target.native_client_fixture;
SELECT id, label FROM native_client_fixture ORDER BY id;
START TRANSACTION READ ONLY;
SAVEPOINT qualified;
SELECT count(*) FROM native_client_fixture;
ROLLBACK TO SAVEPOINT qualified;
RELEASE SAVEPOINT qualified;
COMMIT;
SHOW GRANTS;
SELECT 'mysql-recovered';
`
		output := runContainerClient(t, image, []string{"MYSQL_PWD=" + syntheticPassword}, arguments, script)
		for _, expected := range []string{"mysql-connected\tcrucible_target", "native_client_fixture", "1\talpha", "2\tbeta", "mysql-recovered"} {
			if !strings.Contains(output, expected) {
				t.Fatalf("mysql qualification output did not contain %q:\n%s", expected, output)
			}
		}

		reconnectOutput := runContainerClient(t, image, []string{"MYSQL_PWD=" + syntheticPassword}, arguments, "SELECT 'mysql-reconnected';\n")
		if !strings.Contains(reconnectOutput, "mysql-reconnected") {
			t.Fatalf("mysql reconnect output was unexpected:\n%s", reconnectOutput)
		}
	})

	if err := connection.Close(); err != nil {
		t.Fatalf("close MySQL qualification connection: %v", err)
	}
	connection = connectMySQL(t, address)
	defer connection.Close()
	assertMySQLConnectionUsable(t, connection)

	redisOptions, err := redis.ParseURL(harness.redisURL)
	if err != nil {
		t.Fatalf("parse integration Redis URL: %v", err)
	}
	redisClient := redis.NewClient(redisOptions)
	defer redisClient.Close()
	revocation, err := json.Marshal(map[string][]string{"lease_ids": {mySQLLeaseID}})
	if err != nil {
		t.Fatalf("encode revocation message: %v", err)
	}
	ctx := context.Background()
	deadline := time.Now().Add(5 * time.Second)
	for time.Now().Before(deadline) {
		if err := redisClient.Publish(ctx, "native-proxy:lease-revoked", revocation).Err(); err != nil {
			t.Fatalf("publish integration revocation: %v", err)
		}
		if _, err := connection.Execute("SELECT 1"); err != nil {
			return
		}
		time.Sleep(100 * time.Millisecond)
	}
	t.Fatal("MySQL client remained connected after native lease revocation")
}

func TestMySQLWriteLeaseAllowsGovernedDML(t *testing.T) {
	harness := integrationHarness(t)
	document, token := harness.issueDeviceToken(t, mySQLWriteDevice)
	address, stopTunnel := harness.startTunnel(t, document, token, mySQLWriteLeaseID, "mysql", "integration-mysql-write-connection")
	defer stopTunnel()

	connection := connectMySQLWithUsername(t, address, "crucible_native_mysql_write")
	defer connection.Close()
	if err := connection.Begin(); err != nil {
		t.Fatalf("start MySQL write transaction: %v", err)
	}
	executeMySQL(t, connection, "INSERT INTO native_client_fixture (id, label) VALUES (?, ?)", int64(99), "created")
	executeMySQL(t, connection, "UPDATE native_client_fixture SET label = ? WHERE id = ?", "updated", int64(99))
	result := executeMySQL(t, connection, "SELECT label FROM native_client_fixture WHERE id = ?", int64(99))
	if label := mySQLString(t, result, 0, 0); label != "updated" {
		t.Fatalf("unexpected MySQL write result: %q", label)
	}
	result = executeMySQL(t, connection, "DELETE FROM native_client_fixture WHERE id = ?", int64(99))
	if result.AffectedRows != 1 {
		t.Fatalf("unexpected MySQL delete row count: %d", result.AffectedRows)
	}
	if err := connection.Rollback(); err != nil {
		t.Fatalf("rollback MySQL write transaction: %v", err)
	}
	result = executeMySQL(t, connection, "SELECT count(*) FROM native_client_fixture WHERE id = 99")
	if count := mySQLInt(t, result, 0, 0); count != 0 {
		t.Fatalf("MySQL qualification write escaped rollback: count=%d", count)
	}

	t.Run("mysql write executable", func(t *testing.T) {
		host, port, err := net.SplitHostPort(address)
		if err != nil {
			t.Fatalf("parse MySQL write listener: %v", err)
		}
		output := runContainerClient(t, environment("NATIVE_INTEGRATION_MYSQL_CLIENT_IMAGE", ""), []string{"MYSQL_PWD=" + syntheticPassword}, []string{
			"mysql", "--protocol=TCP", "--ssl-mode=DISABLED", "--host=" + host, "--port=" + port,
			"--user=crucible_native_mysql_write", "--database=crucible_target", "--batch", "--raw",
			"--skip-column-names",
		}, `START TRANSACTION;
INSERT INTO native_client_fixture (id, label) VALUES (100, 'mysql-created');
UPDATE native_client_fixture SET label = 'mysql-updated' WHERE id = 100;
SELECT label FROM native_client_fixture WHERE id = 100;
DELETE FROM native_client_fixture WHERE id = 100;
ROLLBACK;
SELECT count(*) FROM native_client_fixture WHERE id = 100;
`)
		if !strings.Contains(output, "mysql-updated") || !strings.HasSuffix(strings.TrimSpace(output), "0") {
			t.Fatalf("unexpected mysql write qualification output:\n%s", output)
		}
	})
}

func connectMySQL(t *testing.T, address string) *client.Conn {
	return connectMySQLWithUsername(t, address, "crucible_native_mysql")
}

func connectMySQLWithUsername(t *testing.T, address string, username string) *client.Conn {
	t.Helper()
	connection, err := client.Connect(address, username, syntheticPassword, "crucible_target")
	if err != nil {
		t.Fatalf("connect MySQL client through native proxy: %v", err)
	}

	return connection
}

func executeMySQL(t *testing.T, connection *client.Conn, statement string, arguments ...any) *mysqlproto.Result {
	t.Helper()
	result, err := connection.Execute(statement, arguments...)
	if err != nil {
		t.Fatalf("execute MySQL statement %q: %v", statement, err)
	}

	return result
}

func mySQLInt(t *testing.T, result *mysqlproto.Result, row int, column int) int64 {
	t.Helper()
	if result == nil || result.Resultset == nil {
		t.Fatal("MySQL statement did not return a result set")
	}
	value, err := result.Resultset.GetInt(row, column)
	if err != nil {
		t.Fatalf("read MySQL integer result: %v", err)
	}

	return value
}

func mySQLString(t *testing.T, result *mysqlproto.Result, row int, column int) string {
	t.Helper()
	if result == nil || result.Resultset == nil {
		t.Fatal("MySQL statement did not return a result set")
	}
	value, err := result.Resultset.GetString(row, column)
	if err != nil {
		t.Fatalf("read MySQL string result: %v", err)
	}

	return value
}

func assertMySQLConnectionUsable(t *testing.T, connection *client.Conn) {
	t.Helper()
	result := executeMySQL(t, connection, "SELECT 1")
	if value := mySQLInt(t, result, 0, 0); value != 1 {
		t.Fatalf("unexpected MySQL recovery result: %d", value)
	}
}
