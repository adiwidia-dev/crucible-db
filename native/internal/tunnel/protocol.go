package tunnel

import "errors"

const CurrentProtocol = "crucible.tunnel.v1"

const (
	CloseCodeProtocolError      = 4400
	CloseCodeUnauthorized       = 4401
	CloseCodeForbidden          = 4403
	CloseCodeUnsupportedVersion = 4406
	CloseCodeInternalError      = 4500
)

var ErrUnsupportedVersion = errors.New("unsupported tunnel protocol version")

type Hello struct {
	Protocol string `json:"protocol"`
}

func Negotiate(hello Hello) (string, error) {
	if hello.Protocol != CurrentProtocol {
		return "", ErrUnsupportedVersion
	}

	return CurrentProtocol, nil
}
