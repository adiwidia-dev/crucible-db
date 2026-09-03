# Crucible CLI

`crucible` creates a loopback-only tunnel for an approved Crucible DB Native Client session. It never creates a target-database user and never exposes PostgreSQL or MySQL protocol ports publicly.

Use the CLI command shown in the approved session, then configure the desktop client to connect to `127.0.0.1` on the printed local port with local database TLS disabled. The CLI tunnel and the target connection's independently configured upstream TLS protect the remote path.

Install, verification, command, and compatibility guidance is maintained in the [Crucible CLI reference](https://adiwidia-dev.github.io/crucible-db/reference/crucible-cli/) and [Native client compatibility matrix](https://adiwidia-dev.github.io/crucible-db/reference/native-client-compatibility/).
