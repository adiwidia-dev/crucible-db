//go:build integration

package integration

import (
	"context"
	"encoding/json"
	"net"
	"reflect"
	"strings"
	"testing"
	"time"

	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgproto3"
	"github.com/redis/go-redis/v9"
)

func TestPostgreSQLClientTraversesDeviceTokenTunnelPolicyAndProxy(t *testing.T) {
	harness := integrationHarness(t)
	document, token := harness.issueDeviceToken(t, postgresDevice)
	address, stopTunnel := harness.startTunnel(t, document, token, postgresLeaseID, "postgresql", "integration-postgresql-connection")
	defer stopTunnel()

	connection := connectPostgreSQL(t, address)
	defer connection.Close(context.Background())
	ctx := context.Background()

	if err := connection.Ping(ctx); err != nil {
		t.Fatalf("ping PostgreSQL through native proxy: %v", err)
	}

	var database string
	var schema string
	if err := connection.QueryRow(ctx, "SELECT current_database(), current_schema()").Scan(&database, &schema); err != nil {
		t.Fatalf("inspect PostgreSQL connection identity: %v", err)
	}
	if database != "crucible_target" || schema != "public" {
		t.Fatalf("unexpected PostgreSQL identity: database=%q schema=%q", database, schema)
	}

	var value int
	if err := connection.QueryRow(ctx, "SELECT count(*) FROM native_client_fixture").Scan(&value); err != nil || value != 2 {
		t.Fatalf("browse PostgreSQL fixture metadata: value=%d err=%v", value, err)
	}
	if err := connection.QueryRow(ctx, "SELECT 40 + 2", pgx.QueryExecModeSimpleProtocol).Scan(&value); err != nil || value != 42 {
		t.Fatalf("execute PostgreSQL simple query through native proxy: value=%d err=%v", value, err)
	}
	if err := connection.QueryRow(ctx, "SELECT $1::integer + 1", 41).Scan(&value); err != nil || value != 42 {
		t.Fatalf("execute PostgreSQL extended query through native proxy: value=%d err=%v", value, err)
	}
	if _, err := connection.Prepare(ctx, "qualified_add", "SELECT $1::integer + $2::integer"); err != nil {
		t.Fatalf("prepare named PostgreSQL statement: %v", err)
	}
	if err := connection.QueryRow(ctx, "qualified_add", 20, 22).Scan(&value); err != nil || value != 42 {
		t.Fatalf("execute named PostgreSQL statement: value=%d err=%v", value, err)
	}
	if err := connection.Deallocate(ctx, "qualified_add"); err != nil {
		t.Fatalf("close named PostgreSQL statement: %v", err)
	}
	if err := connection.QueryRow(ctx, "SELECT 42::integer", pgx.QueryResultFormats{pgx.BinaryFormatCode}).Scan(&value); err != nil || value != 42 {
		t.Fatalf("request binary PostgreSQL result: value=%d err=%v", value, err)
	}

	pgAdminStartupBatch := `SET DateStyle=ISO;
SET client_min_messages=notice;
SELECT set_config('bytea_output','hex',false)
 FROM pg_show_all_settings()
 WHERE name = 'bytea_output';
SET client_encoding='UTF8';`
	results, err := connection.PgConn().Exec(ctx, pgAdminStartupBatch).ReadAll()
	if err != nil {
		t.Fatalf("execute pgAdmin startup batch through native proxy: %v", err)
	}
	if len(results) != 4 {
		t.Fatalf("expected four pgAdmin startup results, got %d", len(results))
	}

	transaction, err := connection.BeginTx(ctx, pgx.TxOptions{AccessMode: pgx.ReadOnly})
	if err != nil {
		t.Fatalf("start read-only PostgreSQL transaction: %v", err)
	}
	if _, err := transaction.Exec(ctx, "SAVEPOINT qualified"); err != nil {
		t.Fatalf("create PostgreSQL savepoint: %v", err)
	}
	if err := transaction.QueryRow(ctx, "SELECT count(*) FROM native_client_fixture").Scan(&value); err != nil || value != 2 {
		t.Fatalf("query inside PostgreSQL transaction: value=%d err=%v", value, err)
	}
	if _, err := transaction.Exec(ctx, "ROLLBACK TO SAVEPOINT qualified"); err != nil {
		t.Fatalf("rollback PostgreSQL savepoint: %v", err)
	}
	if _, err := transaction.Exec(ctx, "RELEASE SAVEPOINT qualified"); err != nil {
		t.Fatalf("release PostgreSQL savepoint: %v", err)
	}
	if err := transaction.Commit(ctx); err != nil {
		t.Fatalf("commit read-only PostgreSQL transaction: %v", err)
	}

	if _, err := connection.Exec(ctx, "BEGIN READ WRITE"); err == nil {
		t.Fatal("native PostgreSQL policy unexpectedly allowed a read-write transaction")
	}
	assertPostgreSQLConnectionUsable(t, connection)
	if _, err := connection.Exec(ctx, "SHOW ALL"); err == nil {
		t.Fatal("native PostgreSQL policy unexpectedly allowed SHOW ALL")
	}
	assertPostgreSQLConnectionUsable(t, connection)
	if _, err := connection.Exec(ctx, "SELECT 1 / 0"); err == nil {
		t.Fatal("PostgreSQL upstream error probe unexpectedly succeeded")
	}
	assertPostgreSQLConnectionUsable(t, connection)

	frontend := connection.PgConn().Frontend()
	frontend.SendParse(&pgproto3.Parse{Name: "paged", Query: "SELECT generate_series(1, 3)"})
	frontend.SendBind(&pgproto3.Bind{DestinationPortal: "paged-portal", PreparedStatement: "paged"})
	frontend.SendDescribe(&pgproto3.Describe{ObjectType: 'P', Name: "paged-portal"})
	frontend.SendExecute(&pgproto3.Execute{Portal: "paged-portal", MaxRows: 1})
	frontend.Send(&pgproto3.Flush{})
	if err := frontend.Flush(); err != nil {
		t.Fatalf("flush first suspended portal execution: %v", err)
	}
	receivePostgreSQLMessage(t, connection, &pgproto3.PortalSuspended{})
	frontend.SendExecute(&pgproto3.Execute{Portal: "paged-portal", MaxRows: 1})
	frontend.Send(&pgproto3.Flush{})
	if err := frontend.Flush(); err != nil {
		t.Fatalf("flush second suspended portal execution: %v", err)
	}
	receivePostgreSQLMessage(t, connection, &pgproto3.PortalSuspended{})
	frontend.SendExecute(&pgproto3.Execute{Portal: "paged-portal"})
	frontend.SendSync(&pgproto3.Sync{})
	if err := frontend.Flush(); err != nil {
		t.Fatalf("flush final portal execution: %v", err)
	}
	receivePostgreSQLMessage(t, connection, &pgproto3.ReadyForQuery{})

	t.Run("psql executable", func(t *testing.T) {
		host, port, err := net.SplitHostPort(address)
		if err != nil {
			t.Fatalf("parse PostgreSQL loopback listener: %v", err)
		}
		image := environment("NATIVE_INTEGRATION_POSTGRES_CLIENT_IMAGE", "")
		arguments := []string{
			"psql", "-X", "--host", host, "--port", port,
			"--username", "crucible_native_postgresql", "--dbname", "crucible_target",
			"--set", "ON_ERROR_STOP=0", "--no-align", "--tuples-only",
		}
		script := `SELECT 'psql-connected', current_database(), current_schema();
\dt public.native_client_fixture
SELECT id, label FROM native_client_fixture ORDER BY id;
BEGIN READ ONLY;
SAVEPOINT qualified;
SELECT count(*) FROM native_client_fixture;
ROLLBACK TO SAVEPOINT qualified;
RELEASE SAVEPOINT qualified;
COMMIT;
SHOW ALL;
SELECT 'psql-recovered';
`
		output := runContainerClient(t, image, []string{"PGPASSWORD=" + syntheticPassword}, arguments, script)
		for _, expected := range []string{"psql-connected|crucible_target|public", "native_client_fixture", "1|alpha", "2|beta", "psql-recovered"} {
			if !strings.Contains(output, expected) {
				t.Fatalf("psql qualification output did not contain %q:\n%s", expected, output)
			}
		}

		reconnectOutput := runContainerClient(t, image, []string{"PGPASSWORD=" + syntheticPassword}, arguments, "SELECT 'psql-reconnected';\n")
		if !strings.Contains(reconnectOutput, "psql-reconnected") {
			t.Fatalf("psql reconnect output was unexpected:\n%s", reconnectOutput)
		}
	})

	cancelled := make(chan error, 1)
	go func() {
		var ignored int
		cancelled <- connection.QueryRow(context.Background(), "SELECT 1 FROM pg_sleep(5)").Scan(&ignored)
	}()
	time.Sleep(250 * time.Millisecond)
	cancelContext, cancel := context.WithTimeout(ctx, 3*time.Second)
	if err := connection.PgConn().CancelRequest(cancelContext); err != nil {
		cancel()
		t.Fatalf("cancel PostgreSQL query through native proxy: %v", err)
	}
	cancel()
	select {
	case err := <-cancelled:
		if err == nil {
			t.Fatal("cancelled PostgreSQL query unexpectedly succeeded")
		}
	case <-time.After(3 * time.Second):
		t.Fatal("PostgreSQL query did not stop after cancellation")
	}
	assertPostgreSQLConnectionUsable(t, connection)

	connection.Close(ctx)
	connection = connectPostgreSQL(t, address)
	defer connection.Close(context.Background())
	assertPostgreSQLConnectionUsable(t, connection)

	redisOptions, err := redis.ParseURL(harness.redisURL)
	if err != nil {
		t.Fatalf("parse integration Redis URL: %v", err)
	}
	redisClient := redis.NewClient(redisOptions)
	defer redisClient.Close()
	revocation, err := json.Marshal(map[string][]string{"lease_ids": {postgresLeaseID}})
	if err != nil {
		t.Fatalf("encode revocation message: %v", err)
	}
	deadline := time.Now().Add(5 * time.Second)
	for time.Now().Before(deadline) {
		if err := redisClient.Publish(ctx, "native-proxy:lease-revoked", revocation).Err(); err != nil {
			t.Fatalf("publish integration revocation: %v", err)
		}
		probeContext, cancel := context.WithTimeout(ctx, 250*time.Millisecond)
		err := connection.QueryRow(probeContext, "SELECT 1").Scan(&value)
		cancel()
		if err != nil {
			return
		}
		time.Sleep(100 * time.Millisecond)
	}
	t.Fatal("PostgreSQL client remained connected after native lease revocation")
}

func TestPostgreSQLWriteLeaseAllowsGovernedDML(t *testing.T) {
	harness := integrationHarness(t)
	document, token := harness.issueDeviceToken(t, postgresWriteDevice)
	address, stopTunnel := harness.startTunnel(t, document, token, postgresWriteLeaseID, "postgresql", "integration-postgresql-write-connection")
	defer stopTunnel()

	connection := connectPostgreSQLWithUsername(t, address, "crucible_native_postgresql_write")
	defer connection.Close(context.Background())
	ctx := context.Background()
	transaction, err := connection.Begin(ctx)
	if err != nil {
		t.Fatalf("start PostgreSQL write transaction: %v", err)
	}
	if _, err := transaction.Exec(ctx, "INSERT INTO native_client_fixture (id, label) VALUES ($1, $2)", 99, "created"); err != nil {
		t.Fatalf("insert through PostgreSQL write lease: %v", err)
	}
	if _, err := transaction.Exec(ctx, "UPDATE native_client_fixture SET label = $1 WHERE id = $2", "updated", 99); err != nil {
		t.Fatalf("update through PostgreSQL write lease: %v", err)
	}
	var label string
	if err := transaction.QueryRow(ctx, "SELECT label FROM native_client_fixture WHERE id = $1", 99).Scan(&label); err != nil || label != "updated" {
		t.Fatalf("read PostgreSQL write result: label=%q err=%v", label, err)
	}
	commandTag, err := transaction.Exec(ctx, "DELETE FROM native_client_fixture WHERE id = $1", 99)
	if err != nil || commandTag.RowsAffected() != 1 {
		t.Fatalf("delete through PostgreSQL write lease: rows=%d err=%v", commandTag.RowsAffected(), err)
	}
	if err := transaction.Rollback(ctx); err != nil {
		t.Fatalf("rollback PostgreSQL write transaction: %v", err)
	}
	var count int
	if err := connection.QueryRow(ctx, "SELECT count(*) FROM native_client_fixture WHERE id = 99").Scan(&count); err != nil || count != 0 {
		t.Fatalf("PostgreSQL qualification write escaped rollback: count=%d err=%v", count, err)
	}

	t.Run("psql write executable", func(t *testing.T) {
		host, port, err := net.SplitHostPort(address)
		if err != nil {
			t.Fatalf("parse PostgreSQL write listener: %v", err)
		}
		output := runContainerClient(t, environment("NATIVE_INTEGRATION_POSTGRES_CLIENT_IMAGE", ""), []string{"PGPASSWORD=" + syntheticPassword}, []string{
			"psql", "-X", "--host", host, "--port", port,
			"--username", "crucible_native_postgresql_write", "--dbname", "crucible_target",
			"--set", "ON_ERROR_STOP=1", "--no-align", "--tuples-only",
		}, `BEGIN;
INSERT INTO native_client_fixture (id, label) VALUES (100, 'psql-created');
UPDATE native_client_fixture SET label = 'psql-updated' WHERE id = 100;
SELECT label FROM native_client_fixture WHERE id = 100;
DELETE FROM native_client_fixture WHERE id = 100;
ROLLBACK;
SELECT count(*) FROM native_client_fixture WHERE id = 100;
`)
		if !strings.Contains(output, "psql-updated") || !strings.HasSuffix(strings.TrimSpace(output), "0") {
			t.Fatalf("unexpected psql write qualification output:\n%s", output)
		}
	})
}

func connectPostgreSQL(t *testing.T, address string) *pgx.Conn {
	return connectPostgreSQLWithUsername(t, address, "crucible_native_postgresql")
}

func connectPostgreSQLWithUsername(t *testing.T, address string, username string) *pgx.Conn {
	t.Helper()
	host, port, err := net.SplitHostPort(address)
	if err != nil {
		t.Fatalf("parse PostgreSQL loopback listener: %v", err)
	}
	config, err := pgx.ParseConfig("postgres://" + username + ":" + syntheticPassword + "@" + net.JoinHostPort(host, port) + "/crucible_target?sslmode=disable")
	if err != nil {
		t.Fatalf("configure PostgreSQL qualification client: %v", err)
	}
	config.DefaultQueryExecMode = pgx.QueryExecModeCacheStatement
	config.RuntimeParams["application_name"] = "Crucible compatibility harness"
	connection, err := pgx.ConnectConfig(context.Background(), config)
	if err != nil {
		t.Fatalf("connect PostgreSQL client through native proxy: %v", err)
	}

	return connection
}

func assertPostgreSQLConnectionUsable(t *testing.T, connection *pgx.Conn) {
	t.Helper()
	var value int
	if err := connection.QueryRow(context.Background(), "SELECT 1").Scan(&value); err != nil || value != 1 {
		t.Fatalf("reuse PostgreSQL connection after error: value=%d err=%v", value, err)
	}
}

func receivePostgreSQLMessage(t *testing.T, connection *pgx.Conn, target pgproto3.BackendMessage) {
	t.Helper()
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	for {
		message, err := connection.PgConn().ReceiveMessage(ctx)
		if err != nil {
			t.Fatalf("receive PostgreSQL extended-protocol message: %v", err)
		}
		if databaseError, ok := message.(*pgproto3.ErrorResponse); ok {
			t.Fatalf("PostgreSQL extended protocol returned %s: %s", databaseError.Code, databaseError.Message)
		}
		if reflect.TypeOf(message) == reflect.TypeOf(target) {
			return
		}
	}
}
