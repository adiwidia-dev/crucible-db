package postgres

import (
	"errors"
	"strings"
)

var errMalformedSimpleQuery = errors.New("malformed PostgreSQL simple query")

// splitPostgreSQLStatements separates a PostgreSQL simple-query message into
// statements while respecting PostgreSQL strings, identifiers, dollar quotes,
// and comments. Statements are still authorized individually before execution.
func splitPostgreSQLStatements(sql string) ([]string, error) {
	if hasUnsafeComment(sql) {
		return nil, errMalformedSimpleQuery
	}

	statements := make([]string, 0, 1)
	statementStart := 0
	blockCommentDepth := 0
	lineComment := false
	singleQuoted := false
	singleQuotedEscapes := false
	doubleQuoted := false
	doubleQuotedEscapes := false
	dollarQuote := ""
	statementHasContent := false

	appendStatement := func(end int) {
		statement := strings.TrimSpace(sql[statementStart:end])
		if statement != "" && statementHasContent {
			statements = append(statements, statement)
		}
		statementHasContent = false
	}

	for index := 0; index < len(sql); index++ {
		if lineComment {
			if sql[index] == '\n' || sql[index] == '\r' {
				lineComment = false
			}
			continue
		}

		if blockCommentDepth > 0 {
			if hasPrefixAt(sql, index, "/*") {
				blockCommentDepth++
				index++
				continue
			}
			if hasPrefixAt(sql, index, "*/") {
				blockCommentDepth--
				index++
			}
			continue
		}

		if dollarQuote != "" {
			if hasPrefixAt(sql, index, dollarQuote) {
				index += len(dollarQuote) - 1
				dollarQuote = ""
			}
			continue
		}

		if singleQuoted {
			if singleQuotedEscapes && sql[index] == '\\' && index+1 < len(sql) {
				index++
				continue
			}
			if sql[index] == '\'' {
				if index+1 < len(sql) && sql[index+1] == '\'' {
					index++
					continue
				}
				singleQuoted = false
			}
			continue
		}

		if doubleQuoted {
			if doubleQuotedEscapes && sql[index] == '\\' && index+1 < len(sql) {
				index++
				continue
			}
			if sql[index] == '"' {
				if index+1 < len(sql) && sql[index+1] == '"' {
					index++
					continue
				}
				doubleQuoted = false
			}
			continue
		}

		switch {
		case hasPrefixAt(sql, index, "--"):
			lineComment = true
			index++
		case hasPrefixAt(sql, index, "/*"):
			blockCommentDepth = 1
			index++
		case sql[index] == '\'':
			statementHasContent = true
			singleQuoted = true
			singleQuotedEscapes = hasEscapeStringPrefix(sql, index)
		case sql[index] == '"':
			statementHasContent = true
			doubleQuoted = true
			doubleQuotedEscapes = hasUnicodeQuotedIdentifierPrefix(sql, index)
		case sql[index] == '$':
			if tag := dollarQuoteTagAt(sql, index); tag != "" {
				statementHasContent = true
				dollarQuote = tag
				index += len(tag) - 1
			} else {
				statementHasContent = true
			}
		case sql[index] == ';':
			appendStatement(index)
			statementStart = index + 1
		case !isPostgreSQLWhitespace(sql[index]):
			statementHasContent = true
		}
	}

	if blockCommentDepth > 0 || singleQuoted || doubleQuoted || dollarQuote != "" {
		return nil, errMalformedSimpleQuery
	}

	appendStatement(len(sql))

	return statements, nil
}

func isPostgreSQLWhitespace(character byte) bool {
	return character == ' ' || character == '\t' || character == '\n' || character == '\r' || character == '\f'
}

func hasUnsafeComment(sql string) bool {
	return strings.Contains(sql, "/*!") || strings.Contains(sql, "/*+")
}

func hasPrefixAt(value string, index int, prefix string) bool {
	return index >= 0 && index+len(prefix) <= len(value) && value[index:index+len(prefix)] == prefix
}

func dollarQuoteTagAt(sql string, index int) string {
	if index > 0 && isPostgreSQLIdentifierCharacter(sql[index-1]) {
		return ""
	}

	for end := index + 1; end < len(sql); end++ {
		if sql[end] == '$' {
			return sql[index : end+1]
		}
		if !isDollarQuoteTagCharacter(sql[end], end == index+1) {
			return ""
		}
	}

	return ""
}

func isDollarQuoteTagCharacter(character byte, first bool) bool {
	if character >= 'a' && character <= 'z' || character >= 'A' && character <= 'Z' || character == '_' || character >= 0x80 {
		return true
	}

	return !first && character >= '0' && character <= '9'
}

func isPostgreSQLIdentifierCharacter(character byte) bool {
	return isDollarQuoteTagCharacter(character, false) || character == '$'
}

func hasEscapeStringPrefix(sql string, quoteIndex int) bool {
	if quoteIndex >= 1 && (sql[quoteIndex-1] == 'e' || sql[quoteIndex-1] == 'E') &&
		(quoteIndex == 1 || !isPostgreSQLIdentifierCharacter(sql[quoteIndex-2])) {
		return true
	}

	return quoteIndex >= 2 && sql[quoteIndex-1] == '&' &&
		(sql[quoteIndex-2] == 'u' || sql[quoteIndex-2] == 'U') &&
		(quoteIndex == 2 || !isPostgreSQLIdentifierCharacter(sql[quoteIndex-3]))
}

func hasUnicodeQuotedIdentifierPrefix(sql string, quoteIndex int) bool {
	return quoteIndex >= 2 && sql[quoteIndex-1] == '&' &&
		(sql[quoteIndex-2] == 'u' || sql[quoteIndex-2] == 'U') &&
		(quoteIndex == 2 || !isPostgreSQLIdentifierCharacter(sql[quoteIndex-3]))
}
