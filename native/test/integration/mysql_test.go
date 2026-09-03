//go:build integration

package integration

import (
	"testing"

	"github.com/go-mysql-org/go-mysql/client"
)

func TestMySQLClientTraversesDeviceTokenTunnelPolicyAndProxy(t *testing.T) {
	harness := integrationHarness(t)
	document, token := harness.issueDeviceToken(t, mySQLDevice)
	connectionID := "integration-mysql-connection"
	address, stopTunnel := harness.startTunnel(t, document, token, mySQLLeaseID, "mysql", connectionID)
	defer stopTunnel()
	connection, err := client.Connect(address, "crucible_native_mysql", syntheticPassword, "crucible_target")
	if err != nil {
		t.Fatalf("connect MySQL client through native proxy: %v", err)
	}
	defer connection.Close()

	result, err := connection.Execute("SELECT 1")
	if err != nil {
		t.Fatalf("execute MySQL read through native proxy: %v", err)
	}
	if result.Resultset == nil || result.Resultset.RowNumber() != 1 {
		t.Fatal("unexpected MySQL probe result")
	}
	if _, err := connection.Execute("SHOW GRANTS"); err == nil {
		t.Fatal("native MySQL policy unexpectedly allowed SHOW GRANTS")
	}
}
