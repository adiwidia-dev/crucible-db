package command

import (
	"errors"
	"fmt"
	"io"
)

const (
	ExitSuccess       = 0
	ExitRuntime       = 1
	ExitUsage         = 2
	ExitAuthorization = 3
	ExitNetwork       = 4
)

type ExitError struct {
	Code int
	Err  error
}

func (error *ExitError) Error() string {
	return error.Err.Error()
}

func (error *ExitError) Unwrap() error {
	return error.Err
}

func Wrap(code int, err error) error {
	if err == nil {
		return nil
	}

	return &ExitError{Code: code, Err: err}
}

// Run executes a testable command function and returns its process exit code.
func Run(run func() error, stderr io.Writer) int {
	if err := run(); err != nil {
		_, _ = fmt.Fprintln(stderr, err)

		var exitError *ExitError
		if errors.As(err, &exitError) {
			return exitError.Code
		}

		return ExitRuntime
	}

	return ExitSuccess
}
