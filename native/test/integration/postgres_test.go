//go:build integration

package integration

import (
	"context"
	"encoding/json"
	"net"
	"reflect"
	"testing"
	"time"

	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgproto3"
	"github.com/redis/go-redis/v9"
)

func TestPostgreSQLClientTraversesDeviceTokenTunnelPolicyAndProxy(t *testing.T) {
	harness := integrationHarness(t)
	document, token := harness.issueDeviceToken(t, postgresDevice)
	connectionID := "integration-postgresql-connection"
	address, stopTunnel := harness.startTunnel(t, document, token, postgresLeaseID, "postgresql", connectionID)
	defer stopTunnel()
	host, port, err := net.SplitHostPort(address)
	if err != nil {
		t.Fatalf("parse PostgreSQL loopback listener: %v", err)
	}
	config, err := pgx.ParseConfig("postgres://crucible_native_postgresql:" + syntheticPassword + "@" + net.JoinHostPort(host, port) + "/crucible_target?sslmode=disable")
	if err != nil {
		t.Fatalf("configure PostgreSQL qualification client: %v", err)
	}
	config.DefaultQueryExecMode = pgx.QueryExecModeCacheStatement
	connection, err := pgx.ConnectConfig(context.Background(), config)
	if err != nil {
		t.Fatalf("connect PostgreSQL client through native proxy: %v", err)
	}
	defer connection.Close(context.Background())

	var value int
	if err := connection.QueryRow(context.Background(), "SELECT 1").Scan(&value); err != nil || value != 1 {
		t.Fatalf("execute PostgreSQL read through native proxy: value=%d err=%v", value, err)
	}
	if err := connection.QueryRow(context.Background(), "SELECT $1::integer + 1", 41).Scan(&value); err != nil || value != 42 {
		t.Fatalf("execute PostgreSQL extended query through native proxy: value=%d err=%v", value, err)
	}
	pgAdminStartupBatch := `SET DateStyle=ISO;
SET client_min_messages=notice;
SELECT set_config('bytea_output','hex',false)
 FROM pg_show_all_settings()
 WHERE name = 'bytea_output';
SET client_encoding='UTF8';`
	results, err := connection.PgConn().Exec(context.Background(), pgAdminStartupBatch).ReadAll()
	if err != nil {
		t.Fatalf("execute pgAdmin startup batch through native proxy: %v", err)
	}
	if len(results) != 4 {
		t.Fatalf("expected four pgAdmin startup results, got %d", len(results))
	}
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
	if _, err := connection.Exec(context.Background(), "SHOW ALL"); err == nil {
		t.Fatal("native PostgreSQL policy unexpectedly allowed SHOW ALL")
	}

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
		if err := redisClient.Publish(context.Background(), "native-proxy:lease-revoked", revocation).Err(); err != nil {
			t.Fatalf("publish integration revocation: %v", err)
		}
		probeContext, cancel := context.WithTimeout(context.Background(), 250*time.Millisecond)
		err := connection.QueryRow(probeContext, "SELECT 1").Scan(&value)
		cancel()
		if err != nil {
			return
		}
		time.Sleep(100 * time.Millisecond)
	}
	t.Fatal("PostgreSQL client remained connected after native lease revocation")
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
