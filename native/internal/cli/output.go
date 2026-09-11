package cli

import (
	"encoding/json"
	"fmt"
	"io"
)

type output struct {
	writer io.Writer
	json   bool
}

func (output output) event(name string, values map[string]string) {
	if output.json {
		values["event"] = name
		_ = json.NewEncoder(output.writer).Encode(values)

		return
	}

	switch name {
	case "authorization_required":
		_, _ = fmt.Fprintf(
			output.writer,
			"Open this URL to approve the device:\n  %s\n\nDevice code:\n  %s\n",
			values["verification_uri"],
			values["user_code"],
		)
	case "authorization_reused":
		_, _ = fmt.Fprintln(output.writer, "This CLI device is already approved for this access window.")
	case "authorization_not_loaded":
		_, _ = fmt.Fprintln(output.writer, "Saved device approval could not be read; browser approval is required again.")
	case "authorization_not_saved":
		_, _ = fmt.Fprintln(output.writer, "Device approval could not be remembered securely; this connection can still continue.")
	case "update_available":
		_, _ = fmt.Fprintf(output.writer, "A newer Crucible CLI version (%s) is available.\n", values["latest_version"])
	case "listening":
		_, _ = fmt.Fprintf(output.writer, "Listening for %s on %s.\n", values["protocol"], values["address"])
	case "authorized":
		_, _ = fmt.Fprintln(output.writer, "Native client access authorized.")
	case "access_expired":
		_, _ = fmt.Fprintln(output.writer, "Access window ended. Local listener and database connections closed.")
	case "access_ended":
		_, _ = fmt.Fprintln(output.writer, "Access was ended in Crucible. Local listener and database connections closed.")
	}
}
