package mysql

import (
	"context"
	"errors"
	"fmt"

	"github.com/adiwidia-dev/crucible-db/native/internal/control"
	"github.com/go-mysql-org/go-mysql/client"
)

func open(ctx context.Context, upstream control.UpstreamConfiguration, readOnly bool) (*client.Conn, error) {
	if upstream.Host == "" || upstream.Port < 1 || upstream.Database == "" || upstream.Username == "" {
		return nil, errors.New("invalid upstream configuration")
	}
	if err := ctx.Err(); err != nil {
		return nil, err
	}
	address := fmt.Sprintf("%s:%d", upstream.Host, upstream.Port)
	var connection *client.Conn
	var err error
	if upstream.TLSMode != "disabled" {
		verify := upstream.TLSMode == "verify_ca" || upstream.TLSMode == "verify_identity"
		if verify && upstream.TLSCACertificate == "" {
			return nil, errors.New("verified upstream TLS requires a CA certificate")
		}
		configuration := client.NewClientTLSConfig(
			[]byte(upstream.TLSCACertificate), []byte(upstream.TLSClientCertificate), []byte(upstream.TLSClientKey),
			!verify, upstream.Host,
		)
		connection, err = client.Connect(address, upstream.Username, upstream.Password, upstream.Database, func(candidate *client.Conn) error {
			candidate.SetTLSConfig(configuration)
			return nil
		})
	} else {
		connection, err = client.Connect(address, upstream.Username, upstream.Password, upstream.Database)
	}
	if err != nil {
		return nil, err
	}
	if readOnly {
		if _, err := connection.Execute("SET SESSION TRANSACTION READ ONLY"); err != nil {
			_ = connection.Close()
			return nil, err
		}
	}

	return connection, nil
}
