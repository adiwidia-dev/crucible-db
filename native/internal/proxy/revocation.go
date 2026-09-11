package proxy

import (
	"context"
	"encoding/json"
	"strings"
	"time"

	"github.com/redis/go-redis/v9"
)

type revocationMessage struct {
	LeaseIDs []string `json:"lease_ids"`
}

type RevocationSubscriber struct {
	client   *redis.Client
	channel  string
	registry *ConnectionRegistry
}

func NewRevocationSubscriber(redisURL, channel string, registry *ConnectionRegistry) (*RevocationSubscriber, error) {
	if strings.TrimSpace(redisURL) == "" || strings.TrimSpace(channel) == "" || registry == nil {
		return nil, nil
	}
	options, err := redis.ParseURL(redisURL)
	if err != nil {
		return nil, err
	}

	return &RevocationSubscriber{client: redis.NewClient(options), channel: channel, registry: registry}, nil
}

func CheckRedis(ctx context.Context, redisURL string) error {
	options, err := redis.ParseURL(redisURL)
	if err != nil {
		return err
	}
	client := redis.NewClient(options)
	defer client.Close()

	return client.Ping(ctx).Err()
}

func (subscriber *RevocationSubscriber) Run(ctx context.Context) error {
	if subscriber == nil {
		return nil
	}
	defer subscriber.client.Close()
	for {
		if err := subscriber.consume(ctx); err != nil && ctx.Err() == nil {
			select {
			case <-ctx.Done():
				return nil
			case <-time.After(time.Second):
			}
			continue
		}

		return nil
	}
}

func (subscriber *RevocationSubscriber) consume(ctx context.Context) error {
	pubsub := subscriber.client.Subscribe(ctx, subscriber.channel)
	defer pubsub.Close()
	if _, err := pubsub.Receive(ctx); err != nil {
		return err
	}
	channel := pubsub.Channel()
	for {
		select {
		case <-ctx.Done():
			return nil
		case message, ok := <-channel:
			if !ok {
				return context.Canceled
			}
			var revocation revocationMessage
			if json.Unmarshal([]byte(message.Payload), &revocation) != nil {
				continue
			}
			for _, leaseID := range revocation.LeaseIDs {
				subscriber.registry.RevokeLease(leaseID)
			}
		}
	}
}
