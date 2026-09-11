package postgres

import (
	"crypto/rand"
	"errors"

	"github.com/xdg-go/scram"
)

func credentials(username, password string) (scram.StoredCredentials, error) {
	salt := make([]byte, 16)
	if _, err := rand.Read(salt); err != nil {
		return scram.StoredCredentials{}, err
	}
	client, err := scram.SHA256.NewClient(username, password, "")
	if err != nil {
		return scram.StoredCredentials{}, err
	}
	return client.GetStoredCredentialsWithError(scram.KeyFactors{Salt: string(salt), Iters: 4096})
}

func newSCRAMServer(username, password string) (*scram.Server, error) {
	stored, err := credentials(username, password)
	if err != nil {
		return nil, err
	}
	return scram.SHA256.NewServer(func(candidate string) (scram.StoredCredentials, error) {
		// PostgreSQL binds the authenticated identity to the startup packet. Some
		// clients, including pgJDBC, intentionally use "*" as the SCRAM username
		// because the protocol exchange does not carry a separate username.
		if candidate != "" && candidate != "*" && candidate != username {
			return scram.StoredCredentials{}, errors.New("invalid credentials")
		}
		return stored, nil
	})
}
