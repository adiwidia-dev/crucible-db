package postgres

import (
	"bytes"
	"testing"

	"github.com/jackc/pgx/v5/pgproto3"
)

func FuzzFrontendMessage(f *testing.F) {
	f.Add([]byte{'Q', 0, 0, 0, 5, 0})
	f.Add([]byte{'P', 0, 0, 0, 4})
	f.Fuzz(func(t *testing.T, message []byte) {
		backend := pgproto3.NewBackend(bytes.NewReader(message), bytes.NewBuffer(nil))
		backend.SetMaxBodyLen(maxFrontendMessageBytes)
		_, _ = backend.Receive()
	})
}
