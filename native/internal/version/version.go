package version

import (
	"strconv"
	"strings"
)

var (
	Version  = "dev"
	Revision = "none"
	Date     = "unknown"
)

// IsOlder reports whether a stable semantic current release is older than a
// stable semantic latest release. Development and malformed build versions are
// intentionally excluded from update notices.
func IsOlder(current, latest string) bool {
	currentParts, currentOK := semanticParts(current)
	latestParts, latestOK := semanticParts(latest)
	if !currentOK || !latestOK {
		return false
	}

	for index := range currentParts {
		if currentParts[index] != latestParts[index] {
			return currentParts[index] < latestParts[index]
		}
	}

	return false
}

func semanticParts(value string) ([3]int, bool) {
	value = strings.TrimPrefix(strings.TrimSpace(value), "v")
	if value == "" || strings.ContainsAny(value, "+-") {
		return [3]int{}, false
	}
	parts := strings.Split(value, ".")
	if len(parts) != 3 {
		return [3]int{}, false
	}
	result := [3]int{}
	for index, part := range parts {
		parsed, err := strconv.Atoi(part)
		if err != nil || parsed < 0 {
			return [3]int{}, false
		}
		result[index] = parsed
	}

	return result, true
}
