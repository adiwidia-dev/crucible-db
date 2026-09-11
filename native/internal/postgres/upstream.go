package postgres

import (
	"context"
	"crypto/tls"
	"crypto/x509"
	"errors"

	"github.com/adiwidia-dev/crucible-db/native/internal/control"
	"github.com/jackc/pgx/v5"
)

func connect(ctx context.Context, upstream control.UpstreamConfiguration, applicationName string, readOnly bool) (*pgx.Conn, error) {
	config, err := upstreamConfig(upstream, applicationName)
	if err != nil {
		return nil, err
	}
	connection, err := pgx.ConnectConfig(ctx, config)
	if err != nil {
		return nil, err
	}
	if readOnly {
		if _, err := connection.Exec(ctx, "SET default_transaction_read_only = on"); err != nil {
			connection.Close(ctx)
			return nil, err
		}
	}

	return connection, nil
}

func upstreamConfig(upstream control.UpstreamConfiguration, applicationName string) (*pgx.ConnConfig, error) {
	if upstream.Host == "" || upstream.Port < 1 || upstream.Database == "" || upstream.Username == "" {
		return nil, errors.New("invalid upstream configuration")
	}
	config, err := pgx.ParseConfig("sslmode=disable")
	if err != nil {
		return nil, err
	}
	config.Host = upstream.Host
	config.Port = uint16(upstream.Port)
	config.Database = upstream.Database
	config.User = upstream.Username
	config.Password = upstream.Password
	config.Fallbacks = nil
	config.RuntimeParams = make(map[string]string)
	config.DefaultQueryExecMode = pgx.QueryExecModeSimpleProtocol
	if upstream.TLSMode != "disabled" {
		config.TLSConfig, err = tlsConfig(upstream)
		if err != nil {
			return nil, err
		}
	}
	if applicationName != "" {
		config.RuntimeParams["application_name"] = applicationName
	}

	return config, nil
}

func tlsConfig(upstream control.UpstreamConfiguration) (*tls.Config, error) {
	config := &tls.Config{ServerName: upstream.Host, MinVersion: tls.VersionTLS12}
	if upstream.TLSMode == "preferred" || upstream.TLSMode == "required" {
		config.InsecureSkipVerify = true
	}
	if upstream.TLSCACertificate != "" {
		pool := x509.NewCertPool()
		if !pool.AppendCertsFromPEM([]byte(upstream.TLSCACertificate)) {
			return nil, errors.New("invalid upstream CA")
		}
		config.RootCAs = pool
	}
	if upstream.TLSClientCertificate != "" || upstream.TLSClientKey != "" {
		certificate, err := tls.X509KeyPair([]byte(upstream.TLSClientCertificate), []byte(upstream.TLSClientKey))
		if err != nil {
			return nil, err
		}
		config.Certificates = []tls.Certificate{certificate}
	}
	return config, nil
}
