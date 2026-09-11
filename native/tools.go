//go:build tools

package tools

import (
	_ "github.com/coder/websocket"
	_ "github.com/go-mysql-org/go-mysql/server"
	_ "github.com/google/go-cmp/cmp"
	_ "github.com/jackc/pgx/v5"
	_ "github.com/prometheus/client_golang/prometheus"
	_ "github.com/redis/go-redis/v9"
	_ "github.com/spf13/cobra"
	_ "github.com/xdg-go/scram"
)
