package postgres

import (
	"context"
	"net"
	"time"
)

func (server *Server) heartbeat(ctx context.Context, cancel context.CancelFunc, connection net.Conn, connectionID string) {
	ticker := time.NewTicker(2 * time.Second)
	defer ticker.Stop()
	for {
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
			decision, err := server.Control.HeartbeatConnection(ctx, connectionID)
			if err != nil || !decision.Continue {
				cancel()
				_ = connection.Close()

				return
			}
		}
	}
}
