package cli

import (
	"context"
	"errors"
	"fmt"
	"time"

	"github.com/adiwidia-dev/crucible-db/native/internal/control"
)

const maxConsecutiveHeartbeatFailures = 3

var ErrLeaseEnded = errors.New("native client access was ended")

type LeaseClient interface {
	HeartbeatLease(context.Context, string, string, string) (control.LeaseHeartbeatResponse, error)
}

func monitorLease(ctx context.Context, client LeaseClient, endpoint, accessToken, deviceID string, interval time.Duration) error {
	ticker := time.NewTicker(interval)
	defer ticker.Stop()
	consecutiveFailures := 0

	for {
		select {
		case <-ctx.Done():
			return nil
		case <-ticker.C:
			result, err := client.HeartbeatLease(ctx, endpoint, accessToken, deviceID)
			if err != nil {
				consecutiveFailures++
				if consecutiveFailures >= maxConsecutiveHeartbeatFailures {
					return fmt.Errorf("verify native client access: %w", err)
				}

				continue
			}
			consecutiveFailures = 0
			if !result.Continue {
				return ErrLeaseEnded
			}
		}
	}
}
