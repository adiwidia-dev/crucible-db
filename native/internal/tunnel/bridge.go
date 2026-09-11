package tunnel

import (
	"context"
	"errors"
	"io"
	"net"

	"github.com/coder/websocket"
)

type Traffic struct {
	BytesReceived int64
	BytesSent     int64
}

// Bridge copies a single database TCP connection across a binary WebSocket.
// Closing either side closes the other, so connection lifetime remains bounded.
func Bridge(ctx context.Context, tcpConnection net.Conn, websocketConnection *websocket.Conn) error {
	_, err := BridgeWithTraffic(ctx, tcpConnection, websocketConnection)

	return err
}

// BridgeWithTraffic returns the byte counts observed at the proxy-facing TCP
// connection. Received bytes traveled from the local client to the proxy;
// sent bytes traveled from the proxy back to the local client.
func BridgeWithTraffic(ctx context.Context, tcpConnection net.Conn, websocketConnection *websocket.Conn) (Traffic, error) {
	if tcpConnection == nil || websocketConnection == nil {
		return Traffic{}, errors.New("tunnel bridge requires TCP and WebSocket connections")
	}
	websocketConnection.SetReadLimit(MaxMessageBytes)
	bridgeContext, cancel := context.WithCancel(ctx)
	defer cancel()

	type copyResult struct {
		bytes int64
		sent  bool
		err   error
	}
	errC := make(chan copyResult, 2)
	go func() {
		var total int64
		buffer := make([]byte, 32<<10)
		for {
			count, err := tcpConnection.Read(buffer)
			if count > 0 {
				total += int64(count)
				if writeErr := websocketConnection.Write(bridgeContext, websocket.MessageBinary, buffer[:count]); writeErr != nil {
					errC <- copyResult{bytes: total, sent: true, err: writeErr}

					return
				}
			}
			if err != nil {
				errC <- copyResult{bytes: total, sent: true, err: err}

				return
			}
		}
	}()
	go func() {
		var total int64
		for {
			messageType, payload, err := websocketConnection.Read(bridgeContext)
			if err != nil {
				errC <- copyResult{bytes: total, err: err}

				return
			}
			if messageType != websocket.MessageBinary {
				errC <- copyResult{bytes: total, err: errors.New("tunnel only accepts binary WebSocket frames")}

				return
			}
			if err := writeAll(tcpConnection, payload); err != nil {
				errC <- copyResult{bytes: total, err: err}

				return
			}
			total += int64(len(payload))
		}
	}()

	first := <-errC
	_ = tcpConnection.Close()
	websocketConnection.CloseNow()
	second := <-errC
	traffic := Traffic{}
	for _, result := range []copyResult{first, second} {
		if result.sent {
			traffic.BytesSent += result.bytes
		} else {
			traffic.BytesReceived += result.bytes
		}
	}
	if errors.Is(first.err, io.EOF) || errors.Is(first.err, net.ErrClosed) || errors.Is(first.err, context.Canceled) {
		return traffic, nil
	}

	return traffic, first.err
}

func writeAll(writer io.Writer, payload []byte) error {
	for len(payload) > 0 {
		count, err := writer.Write(payload)
		if err != nil {
			return err
		}
		payload = payload[count:]
	}

	return nil
}
