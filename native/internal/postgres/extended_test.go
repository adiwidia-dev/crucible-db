package postgres

import (
	"reflect"
	"testing"

	"github.com/jackc/pgx/v5/pgproto3"
)

func TestExtendedStateSupportsNamedAndUnnamedStatementsAndPortals(t *testing.T) {
	state := newExtendedState()
	state.statements[""] = preparedStatement{sql: "select 1"}
	state.statements["named"] = preparedStatement{sql: "select 2"}
	state.portals[""] = &portal{statement: state.statements[""]}
	state.portals["portal"] = &portal{statement: state.statements["named"]}

	if state.statements[""].sql != "select 1" || state.portals["portal"].statement.sql != "select 2" {
		t.Fatal("expected named and unnamed extended-protocol state to be independent")
	}
}

func TestExtendedProtocolExpandsTextAndBinaryFormats(t *testing.T) {
	tests := []struct {
		name     string
		formats  []int16
		count    int
		expected []int16
	}{
		{name: "defaults to text", count: 2, expected: []int16{pgproto3.TextFormat, pgproto3.TextFormat}},
		{name: "one format applies to all", formats: []int16{pgproto3.BinaryFormat}, count: 2, expected: []int16{pgproto3.BinaryFormat, pgproto3.BinaryFormat}},
		{name: "per value formats", formats: []int16{pgproto3.TextFormat, pgproto3.BinaryFormat}, count: 2, expected: []int16{pgproto3.TextFormat, pgproto3.BinaryFormat}},
	}
	for _, test := range tests {
		t.Run(test.name, func(t *testing.T) {
			actual, err := expandedFormats(test.formats, test.count)
			if err != nil {
				t.Fatalf("expand formats: %v", err)
			}
			if !reflect.DeepEqual(actual, test.expected) {
				t.Fatalf("expected formats %#v, got %#v", test.expected, actual)
			}
		})
	}
}

func TestExtendedProtocolRejectsInvalidFormats(t *testing.T) {
	for _, formats := range [][]int16{{pgproto3.TextFormat, pgproto3.BinaryFormat}, {2}} {
		if _, err := expandedFormats(formats, 3); err == nil {
			t.Fatalf("expected invalid formats %#v to be rejected", formats)
		}
	}
}

func TestExtendedStateHasExplicitMessageLimits(t *testing.T) {
	state := newExtendedState()
	for index := 0; index < maxPreparedStatements; index++ {
		state.statements[string(rune(index+1))] = preparedStatement{sql: "select 1"}
	}
	if len(state.statements) != maxPreparedStatements {
		t.Fatal("expected prepared statement limit")
	}
}
