package main

import (
	"os"

	"github.com/adiwidia-dev/crucible-db/native/internal/cli"
	"github.com/adiwidia-dev/crucible-db/native/internal/command"
)

func main() {
	os.Exit(command.Run(run, os.Stderr))
}

func run() error {
	return cli.NewRootCommand(cli.Dependencies{
		Stdout: os.Stdout,
		Stderr: os.Stderr,
	}).Execute()
}
