package cli_test

import (
	"context"
	"testing"
	"time"

	"github.com/adiwidia-dev/crucible-db/native/internal/cli"
	"github.com/adiwidia-dev/crucible-db/native/internal/control"
)

type pollingClient struct {
	responses []control.DeviceTokenResponse
	index     int
}

func (client *pollingClient) StartDeviceAuthorization(_ context.Context, _ string, _ control.DeviceAuthorizationRequest) (control.DeviceAuthorizationResponse, error) {
	return control.DeviceAuthorizationResponse{DeviceCode: "device-code", UserCode: "ABCD-EFGH", Interval: 5}, nil
}

func (client *pollingClient) PollDeviceToken(_ context.Context, _ string, _ control.DeviceTokenRequest) (control.DeviceTokenResponse, error) {
	response := client.responses[client.index]
	client.index++

	return response, nil
}

func TestDeviceFlowHonorsSlowDownBeforeIssuingAToken(t *testing.T) {
	client := &pollingClient{responses: []control.DeviceTokenResponse{
		{Error: "authorization_pending"},
		{Error: "slow_down", Interval: 10},
		{AccessToken: "token"},
	}}
	var sleeps []time.Duration
	flow := cli.DeviceFlow{
		Client: client,
		Sleep: func(_ context.Context, duration time.Duration) error {
			sleeps = append(sleeps, duration)

			return nil
		},
	}

	result, err := flow.Authorize(context.Background(), "https://example.test/authorize", "https://example.test/token", control.DeviceAuthorizationRequest{LeaseID: "lease-1"}, func(control.DeviceAuthorizationResponse) {})
	if err != nil {
		t.Fatal(err)
	}
	if result.AccessToken != "token" || len(sleeps) != 3 || sleeps[2] != 10*time.Second {
		t.Fatalf("unexpected polling behavior: result=%#v sleeps=%#v", result, sleeps)
	}
}
