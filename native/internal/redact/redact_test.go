package redact_test

import (
	"strings"
	"testing"

	"github.com/adiwidia-dev/crucible-db/native/internal/redact"
)

func TestStringRedactsCredentialBearingValues(t *testing.T) {
	value := redact.String("postgres://admin:very-secret@database.example.test:5432/app password=hunter2 token=abc123")
	if strings.Contains(value, "very-secret") || strings.Contains(value, "hunter2") || strings.Contains(value, "abc123") {
		t.Fatalf("redaction leaked sensitive text: %s", value)
	}
}

func TestStringRedactsSQLLiteralAndDatabaseErrorValues(t *testing.T) {
	input := "duplicate key (email)=('private@example.test') detail=$tag$private-dollar$tag$ hex=0xCAFE bit=0b1010 E'private-escape'"
	value := redact.String(input)
	for _, secret := range []string{"private@example.test", "private-dollar", "CAFE", "1010", "private-escape"} {
		if strings.Contains(value, secret) {
			t.Fatalf("redaction leaked %q in %s", secret, value)
		}
	}
}
