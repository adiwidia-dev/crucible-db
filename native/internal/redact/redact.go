package redact

import "regexp"

var sensitiveValue = regexp.MustCompile(`(?i)(password|token|secret|authorization)\s*([=:])\s*([^\s,;]+)`)
var databaseURLCredential = regexp.MustCompile(`(?i)(://[^:/\s]+:)([^@\s]+)(@)`)
var quotedSQLLiteral = regexp.MustCompile(`(?is)(?:\$[A-Za-z_][A-Za-z0-9_]*\$.*?\$[A-Za-z_][A-Za-z0-9_]*\$|\$\$.*?\$\$|(?:E|U&|B|X|N)?'(?:''|\\.|[^'])*'|0x[0-9a-f]+|0b[01]+)`)
var parenthesizedValue = regexp.MustCompile(`(?i)(=\s*\()([^)]*)(\))`)

// String removes credentials and SQL values from operational error text before
// it can reach a structured log or a metrics label.
func String(value string) string {
	value = databaseURLCredential.ReplaceAllString(value, "${1}[REDACTED]${3}")
	value = quotedSQLLiteral.ReplaceAllString(value, "[REDACTED]")
	value = parenthesizedValue.ReplaceAllString(value, "${1}[REDACTED]${3}")

	return sensitiveValue.ReplaceAllString(value, "${1}${2}[REDACTED]")
}
