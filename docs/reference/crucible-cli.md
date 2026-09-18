# Crucible CLI reference

The `crucible` CLI creates a local tunnel for an approved Native client session. It does not store a target-database credential and it does not update itself automatically. Discovery can show an advisory when the server advertises a newer stable CLI release.

## Install and verify

You can install the CLI before requesting Native Client Access. Select **CLI** in the Crucible application header to open the configured download source. The default source is [GitHub Releases](https://github.com/adiwidia-dev/crucible-db/releases), but an administrator may configure a private release mirror.

### Homebrew

The official Homebrew cask supports macOS and Linux on amd64 and arm64:

```bash
brew install --cask adiwidia-dev/tap/crucible
crucible version
```

Homebrew verifies the cask's release-archive checksum during installation. To
upgrade later, run:

```bash
brew upgrade --cask crucible
```

### GitHub Releases and Linux packages

Download the archive for your operating system and architecture. Verify `checksums.txt` and its keyless `checksums.txt.sigstore.json` bundle before use. The release workflow publishes archives for macOS, Linux, and Windows on amd64 and arm64, plus `.deb` and `.rpm` packages for Linux.

The Linux `.deb` and `.rpm` packages install the same `crucible` binary. Use
your distribution's standard package installer and verify the release
checksums before installing a downloaded package.

```bash
cosign verify-blob \
  --certificate-identity-regexp='https://github.com/adiwidia-dev/crucible-db/' \
  --certificate-oidc-issuer='https://token.actions.githubusercontent.com' \
  --bundle checksums.txt.sigstore.json \
  ./checksums.txt
sha256sum --check checksums.txt
```

## Commands

### `crucible connect`

```text
crucible connect --server https://crucible.example.com --lease LEASE_ID [options]
```

| Option                    | Meaning                                                                                                                         |
| ------------------------- | ------------------------------------------------------------------------------------------------------------------------------- |
| `--server`                | Required HTTPS Crucible application origin. It must not include a query string or fragment.                                     |
| `--lease`                 | Required temporary lease identifier shown in the session workspace.                                                             |
| `--listen`                | Optional loopback address. Defaults to `127.0.0.1:5432` for PostgreSQL and `127.0.0.1:3306` for MySQL.                          |
| `--ca-file`               | Optional PEM file for a private certificate authority that signs the Crucible server certificate. System trust remains enabled. |
| `--authorization-timeout` | Maximum time to wait for browser authorization. Defaults to `6m`.                                                               |
| `--no-browser`            | Do not open the browser automatically; print the verification URI and code instead.                                             |
| `--json`                  | Emit machine-readable progress events.                                                                                          |

The CLI rejects non-HTTPS origins. `--allow-insecure-http` exists only for local development and is hidden from normal help.

The process exits with `2` for invalid flags, `3` when authorization is denied, expires, or times out, `4` for discovery/control-plane failures, and `1` for local listener or tunnel failures. JSON progress events are `authorization_required`, `update_available`, `listening`, and `authorized`; they never contain passwords or access tokens. Human output prints the actual loopback address after authorization.

### Other commands

```text
crucible --help
crucible connect --help
crucible --version
crucible version
crucible completion bash|zsh|fish|powershell
```

`--help` shows the available commands or the options for one command. Both
`crucible --version` and `crucible version` print the installed CLI version.

## Client profiles

Point the client at `127.0.0.1`, use the local driver port, and disable TLS **only for that local client hop**. Copy the one-time username and password from Crucible. Leave the CLI process running for the duration of the database-client session.

No second subdomain, DNS record, proxy port, or client TLS certificate is required. Crucible derives discovery and tunnel URLs from the single `APP_URL` origin.
