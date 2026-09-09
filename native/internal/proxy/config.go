package proxy

import (
	"errors"
	"fmt"
	"net"
	"time"
)

type Config struct {
	PostgreSQLListenAddress string
	MySQLListenAddress      string
	GatewayListenAddress    string
	ControlURL              string
	ControlSecret           string
	RedisURL                string
	RevocationChannel       string
	ProxyID                 string
	MaxConnections          int
	MaxConnectionsPerLease  int
	MaxConnectionsPerUser   int
	IdleTimeout             time.Duration
	DrainTimeout            time.Duration
	ReadinessInterval       time.Duration
}

func (config Config) Validate() error {
	for name, address := range map[string]string{
		"PostgreSQL listener": config.PostgreSQLListenAddress,
		"MySQL listener":      config.MySQLListenAddress,
		"gateway listener":    config.GatewayListenAddress,
	} {
		if _, _, err := net.SplitHostPort(address); err != nil {
			return fmt.Errorf("%s address is invalid", name)
		}
	}
	if config.ControlURL == "" || config.ProxyID == "" {
		return errors.New("native proxy control configuration is required")
	}
	if len(config.ControlSecret) < 32 || config.ControlSecret == "change-me-before-production" {
		return errors.New("native proxy control secret must contain at least 32 non-default characters")
	}
	if config.RedisURL == "" || config.RevocationChannel == "" {
		return errors.New("native proxy revocation configuration is required")
	}
	if config.MaxConnections < 1 || config.MaxConnectionsPerLease < 1 || config.MaxConnectionsPerUser < 1 {
		return errors.New("native proxy connection limits must be positive")
	}
	if config.IdleTimeout <= 0 || config.DrainTimeout <= 0 || config.ReadinessInterval <= 0 {
		return errors.New("native proxy timeouts must be positive")
	}

	return nil
}
