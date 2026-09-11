package postgres

import (
	"os"
	"testing"

	"github.com/adiwidia-dev/crucible-db/native/internal/control"
	"github.com/xdg-go/scram"
)

func TestSCRAMCredentialsAreSaltedAndUsable(t *testing.T) {
	first, err := credentials("crucible_user", "temporary-password")
	if err != nil {
		t.Fatal(err)
	}
	second, err := credentials("crucible_user", "temporary-password")
	if err != nil {
		t.Fatal(err)
	}
	if len(first.StoredKey) == 0 || len(first.ServerKey) == 0 || first.Iters < 4096 {
		t.Fatal("invalid SCRAM credentials")
	}
	if string(first.StoredKey) == string(second.StoredKey) {
		t.Fatal("SCRAM credentials must use a fresh salt")
	}
}

func TestSCRAMAcceptsPostgreSQLClientUsernameConventions(t *testing.T) {
	clientUsernames := map[string]string{
		"empty":              "",
		"pgjdbc_placeholder": "*",
		"startup_username":   "crucible_user",
	}

	for name, clientUsername := range clientUsernames {
		t.Run(name, func(t *testing.T) {
			server, err := newSCRAMServer("crucible_user", "temporary-password")
			if err != nil {
				t.Fatal(err)
			}
			client, err := scram.SHA256.NewClient(clientUsername, "temporary-password", "")
			if err != nil {
				t.Fatal(err)
			}
			serverConversation := server.NewConversation()
			clientConversation := client.NewConversation()

			clientFirst, err := clientConversation.Step("")
			if err != nil {
				t.Fatal(err)
			}
			serverFirst, err := serverConversation.Step(clientFirst)
			if err != nil {
				t.Fatal(err)
			}
			clientFinal, err := clientConversation.Step(serverFirst)
			if err != nil {
				t.Fatal(err)
			}
			serverFinal, err := serverConversation.Step(clientFinal)
			if err != nil {
				t.Fatal(err)
			}
			if _, err := clientConversation.Step(serverFinal); err != nil {
				t.Fatal(err)
			}
			if !serverConversation.Valid() || !clientConversation.Valid() {
				t.Fatal("expected PostgreSQL-style SCRAM conversation to authenticate")
			}
		})
	}
}

func TestSCRAMRejectsAUsernameThatDoesNotMatchPostgreSQLConventions(t *testing.T) {
	server, err := newSCRAMServer("crucible_user", "temporary-password")
	if err != nil {
		t.Fatal(err)
	}
	client, err := scram.SHA256.NewClient("another_user", "temporary-password", "")
	if err != nil {
		t.Fatal(err)
	}
	clientFirst, err := client.NewConversation().Step("")
	if err != nil {
		t.Fatal(err)
	}
	if _, err := server.NewConversation().Step(clientFirst); err == nil {
		t.Fatal("expected an unrelated SCRAM username to be rejected")
	}
}

func TestApplicationNameIsSanitizedAndBounded(t *testing.T) {
	name := sanitizeApplicationName("  DBeaver\x00\n" + string(make([]byte, 80)))
	if len(name) > 64 {
		t.Fatalf("expected application name to be bounded, got %d", len(name))
	}
	for _, character := range name {
		if character < 32 || character > 126 {
			t.Fatalf("unexpected control character in %q", name)
		}
	}
}

func TestTransactionStatusTracksNativeSessionCommands(t *testing.T) {
	status := byte('I')
	updateTransactionStatus("BEGIN", &status)
	if status != 'T' {
		t.Fatal("expected BEGIN to enter a transaction")
	}
	updateTransactionStatus("ROLLBACK", &status)
	if status != 'I' {
		t.Fatal("expected ROLLBACK to leave a transaction")
	}
}

func TestReplicationStartupModesAreRejected(t *testing.T) {
	for _, value := range []string{"true", "database", "1", "on"} {
		if !requestsReplication(map[string]string{"replication": value}) {
			t.Fatalf("expected replication mode %q to be rejected", value)
		}
	}
	for _, value := range []string{"", "false", "0", "off"} {
		if requestsReplication(map[string]string{"replication": value}) {
			t.Fatalf("expected replication mode %q to be accepted", value)
		}
	}
}

func TestStartupDatabaseCannotOverrideApprovedTarget(t *testing.T) {
	if !startupDatabaseAllowed("approved", "approved", "crucible_user") ||
		!startupDatabaseAllowed("", "approved", "crucible_user") ||
		!startupDatabaseAllowed("crucible_user", "approved", "crucible_user") {
		t.Fatal("expected the approved, omitted, or synthetic-user startup database to be accepted")
	}
	if startupDatabaseAllowed("postgres", "approved", "crucible_user") {
		t.Fatal("expected a different startup database to be rejected")
	}
}

func TestUpstreamConfigDoesNotInheritEnvironmentOrParseCredentialValues(t *testing.T) {
	t.Setenv("PGHOST", "untrusted.example.test")
	t.Setenv("PGDATABASE", "untrusted")
	t.Setenv("PGOPTIONS", "-c search_path=untrusted")
	upstream := control.UpstreamConfiguration{
		Host: "database.internal", Port: 5432, Database: "approved database",
		Username: "operator name", Password: "spaces ' quotes \\ remain opaque", TLSMode: "disabled",
	}
	config, err := upstreamConfig(upstream, "DBeaver")
	if err != nil {
		t.Fatal(err)
	}
	if config.Host != upstream.Host || config.Port != uint16(upstream.Port) || config.Database != upstream.Database || config.User != upstream.Username || config.Password != upstream.Password {
		t.Fatalf("typed upstream configuration drifted: %#v", config.Config)
	}
	if len(config.Fallbacks) != 0 || config.RuntimeParams["application_name"] != "DBeaver" || len(config.RuntimeParams) != 1 {
		t.Fatalf("environment-derived upstream settings leaked into config: fallbacks=%v params=%v", config.Fallbacks, config.RuntimeParams)
	}
	if os.Getenv("PGHOST") == config.Host {
		t.Fatal("test did not distinguish the approved host from the process environment")
	}
}
