package control_test

import (
	"encoding/json"
	"os"
	"testing"

	"github.com/adiwidia-dev/crucible-db/native/internal/control"
)

func TestSignatureUsesLaravelCanonicalPayload(t *testing.T) {
	contents, err := os.ReadFile("testdata/signatures.json")
	if err != nil {
		t.Fatal(err)
	}

	var vectors []struct {
		Secret    string `json:"secret"`
		Method    string `json:"method"`
		Path      string `json:"path"`
		Timestamp string `json:"timestamp"`
		RequestID string `json:"request_id"`
		Body      string `json:"body"`
		Signature string `json:"signature"`
	}

	if err := json.Unmarshal(contents, &vectors); err != nil {
		t.Fatal(err)
	}

	for _, vector := range vectors {
		got := control.Signature(vector.Secret, vector.Method, vector.Path, vector.Timestamp, vector.RequestID, []byte(vector.Body))
		if got != vector.Signature {
			t.Fatalf("signature mismatch: got %s want %s", got, vector.Signature)
		}
	}
}
