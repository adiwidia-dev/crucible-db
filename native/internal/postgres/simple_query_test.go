package postgres

import (
	"reflect"
	"testing"
)

func TestSplitPostgreSQLStatements(t *testing.T) {
	testCases := map[string]struct {
		sql      string
		expected []string
	}{
		"multiple statements": {
			sql:      "SET DateStyle=ISO; SET client_min_messages=notice; SELECT 1;",
			expected: []string{"SET DateStyle=ISO", "SET client_min_messages=notice", "SELECT 1"},
		},
		"quoted semicolons": {
			sql:      `SELECT ';', "semi;colon"; SELECT 2`,
			expected: []string{`SELECT ';', "semi;colon"`, "SELECT 2"},
		},
		"dollar quoted semicolons": {
			sql:      `SELECT $$one;two$$; SELECT $tag$three;four$tag$`,
			expected: []string{`SELECT $$one;two$$`, `SELECT $tag$three;four$tag$`},
		},
		"comment semicolons": {
			sql:      "SELECT 1 /* outer; /* inner; */ done; */; -- ignored;\nSELECT 2",
			expected: []string{"SELECT 1 /* outer; /* inner; */ done; */", "-- ignored;\nSELECT 2"},
		},
		"escape strings": {
			sql:      `SELECT E'one\';two'; SELECT U&'three\0026;four'`,
			expected: []string{`SELECT E'one\';two'`, `SELECT U&'three\0026;four'`},
		},
		"empty statements": {
			sql:      "; ; SELECT 1;;",
			expected: []string{"SELECT 1"},
		},
		"dollar signs in identifiers": {
			sql:      `SELECT foo$tag$; SELECT 2`,
			expected: []string{`SELECT foo$tag$`, "SELECT 2"},
		},
	}

	for name, testCase := range testCases {
		t.Run(name, func(t *testing.T) {
			statements, err := splitPostgreSQLStatements(testCase.sql)
			if err != nil {
				t.Fatal(err)
			}
			if !reflect.DeepEqual(statements, testCase.expected) {
				t.Fatalf("expected %#v, got %#v", testCase.expected, statements)
			}
		})
	}
}

func TestSplitPostgreSQLStatementsRejectsUnterminatedSyntax(t *testing.T) {
	for _, sql := range []string{
		"SELECT 'unterminated",
		`SELECT "unterminated`,
		"SELECT $tag$unterminated",
		"SELECT 1 /* unterminated",
	} {
		if _, err := splitPostgreSQLStatements(sql); err == nil {
			t.Fatalf("expected %q to be rejected", sql)
		}
	}
}

func TestSplitPostgreSQLStatementsSupportsPgAdminStartupBatch(t *testing.T) {
	sql := `SET DateStyle=ISO;
SET client_min_messages=notice;
SELECT set_config('bytea_output','hex',false)
 FROM pg_show_all_settings()
 WHERE name = 'bytea_output';
SET client_encoding='UTF8';`

	statements, err := splitPostgreSQLStatements(sql)
	if err != nil {
		t.Fatal(err)
	}
	if len(statements) != 4 {
		t.Fatalf("expected 4 statements, got %#v", statements)
	}
}
