package control

import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"strings"
)

func CanonicalPayload(method, path, timestamp, requestID string, body []byte) string {
	bodyHash := sha256.Sum256(body)

	return strings.Join([]string{
		method,
		"/" + strings.TrimPrefix(path, "/"),
		timestamp,
		requestID,
		hex.EncodeToString(bodyHash[:]),
	}, "\n")
}

func Signature(secret, method, path, timestamp, requestID string, body []byte) string {
	mac := hmac.New(sha256.New, []byte(secret))
	_, _ = mac.Write([]byte(CanonicalPayload(method, path, timestamp, requestID, body)))

	return hex.EncodeToString(mac.Sum(nil))
}
