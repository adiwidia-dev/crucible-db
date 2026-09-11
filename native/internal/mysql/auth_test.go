package mysql

import (
	"testing"

	mysqlproto "github.com/go-mysql-org/go-mysql/mysql"
)

func TestUseDBOnlyAllowsTheLeaseDatabase(t *testing.T) {
	handler := &queryHandler{database: "application"}
	if err := handler.UseDB("application"); err != nil {
		t.Fatal(err)
	}
	if err := handler.UseDB("other"); err == nil {
		t.Fatal("expected cross-database selection to be denied")
	}
}

func TestUseDBDefersHandshakeDatabaseValidationUntilAdmission(t *testing.T) {
	handler := &queryHandler{}
	if err := handler.UseDB("application"); err != nil {
		t.Fatal(err)
	}
	if handler.requestedDatabase != "application" {
		t.Fatal("expected handshake database to be retained for admission validation")
	}
	handler.database = "application"
	if err := handler.UseDB("application"); err != nil {
		t.Fatal(err)
	}
}

func TestMySQLServerDoesNotAdvertiseUnsafeClientCapabilities(t *testing.T) {
	protocol, err := (&Server{}).server()
	if err != nil {
		t.Fatal(err)
	}
	for _, capability := range []uint32{
		mysqlproto.CLIENT_SSL,
		mysqlproto.CLIENT_LOCAL_FILES,
		mysqlproto.CLIENT_MULTI_RESULTS,
		mysqlproto.CLIENT_PS_MULTI_RESULTS,
	} {
		if protocol.Capability()&capability != 0 {
			t.Fatalf("unsafe client capability %#x was advertised", capability)
		}
	}
}

func TestMySQLLoopbackAuthenticationDoesNotRequireTLSOrPublicKeyRetrieval(t *testing.T) {
	authentication := (&Server{}).NewAuthentication("temporary-password")
	credential, found, err := authentication.GetCredential("")
	if err != nil {
		t.Fatal(err)
	}
	if !found {
		t.Fatal("expected the temporary credential to exist")
	}
	if credential.AuthPluginName != mysqlproto.AUTH_NATIVE_PASSWORD {
		t.Fatalf("expected loopback-compatible authentication, got %q", credential.AuthPluginName)
	}
}
