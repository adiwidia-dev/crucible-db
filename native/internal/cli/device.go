package cli

import (
	"context"
	"errors"
	"time"

	"github.com/adiwidia-dev/crucible-db/native/internal/control"
)

var ErrDeviceAuthorizationDenied = errors.New("native client authorization was denied")
var ErrDeviceAuthorizationExpired = errors.New("native client authorization expired")

type DeviceClient interface {
	StartDeviceAuthorization(context.Context, string, control.DeviceAuthorizationRequest) (control.DeviceAuthorizationResponse, error)
	PollDeviceToken(context.Context, string, control.DeviceTokenRequest) (control.DeviceTokenResponse, error)
}

type DeviceFlow struct {
	Client DeviceClient
	Sleep  func(context.Context, time.Duration) error
}

func (flow DeviceFlow) Authorize(ctx context.Context, endpoint, tokenEndpoint string, input control.DeviceAuthorizationRequest, onChallenge func(control.DeviceAuthorizationResponse)) (control.DeviceTokenResponse, error) {
	if flow.Client == nil || flow.Sleep == nil {
		return control.DeviceTokenResponse{}, errors.New("native device authorization is not configured")
	}

	challenge, err := flow.Client.StartDeviceAuthorization(ctx, endpoint, input)
	if err != nil {
		return control.DeviceTokenResponse{}, err
	}
	onChallenge(challenge)
	interval := time.Duration(challenge.Interval) * time.Second
	if interval < 5*time.Second {
		interval = 5 * time.Second
	}

	for {
		if err := flow.Sleep(ctx, interval); err != nil {
			return control.DeviceTokenResponse{}, err
		}

		result, err := flow.Client.PollDeviceToken(ctx, tokenEndpoint, control.DeviceTokenRequest{DeviceCode: challenge.DeviceCode})
		if err != nil {
			return control.DeviceTokenResponse{}, err
		}
		switch result.Error {
		case "":
			return result, nil
		case "authorization_pending":
			continue
		case "slow_down":
			interval = time.Duration(max(result.Interval, int(interval.Seconds())+5)) * time.Second
			continue
		case "access_denied":
			return control.DeviceTokenResponse{}, ErrDeviceAuthorizationDenied
		case "expired_token":
			return control.DeviceTokenResponse{}, ErrDeviceAuthorizationExpired
		default:
			return control.DeviceTokenResponse{}, errors.New("native client authorization failed")
		}
	}
}
