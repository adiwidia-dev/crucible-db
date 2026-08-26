package command_test

import (
	"bytes"
	"errors"
	"testing"

	"github.com/adiwidia-dev/crucible-db/native/internal/command"
)

func TestRunReturnsSuccessWithoutWritingWhenCommandSucceeds(t *testing.T) {
	var stderr bytes.Buffer

	exitCode := command.Run(func() error {
		return nil
	}, &stderr)

	if exitCode != 0 {
		t.Fatalf("expected success exit code, got %d", exitCode)
	}

	if stderr.Len() != 0 {
		t.Fatalf("expected no stderr output, got %q", stderr.String())
	}
}

func TestRunReturnsFailureAndWritesTheError(t *testing.T) {
	var stderr bytes.Buffer

	exitCode := command.Run(func() error {
		return errors.New("unable to start")
	}, &stderr)

	if exitCode != 1 {
		t.Fatalf("expected failure exit code, got %d", exitCode)
	}

	if stderr.String() != "unable to start\n" {
		t.Fatalf("expected error on stderr, got %q", stderr.String())
	}
}
