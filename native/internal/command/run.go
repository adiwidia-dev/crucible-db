package command

import (
	"fmt"
	"io"
)

// Run executes a testable command function and returns its process exit code.
func Run(run func() error, stderr io.Writer) int {
	if err := run(); err != nil {
		_, _ = fmt.Fprintln(stderr, err)

		return 1
	}

	return 0
}
