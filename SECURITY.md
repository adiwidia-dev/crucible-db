# Security policy

## Supported versions

Crucible DB provides security updates for the latest tagged minor release.

| Version | Supported |
| --- | --- |
| `0.1.x` | Yes |
| Earlier or untagged builds | No |

Upgrade to the newest patch release before reporting a problem that may already be resolved.

## Report a vulnerability

Do not disclose suspected vulnerabilities in a public issue, discussion, or pull request. Use GitHub's **Report a vulnerability** form in the repository's **Security** tab to open a private security advisory:

<https://github.com/adiwidia-dev/crucible-db/security/advisories/new>

Include, when possible:

- the affected Crucible DB version and deployment topology;
- a clear description of the impact and required privileges;
- reproducible steps or a minimal proof of concept;
- relevant logs with credentials, tokens, SQL results, and personal data removed; and
- any known mitigation or workaround.

Maintainers will acknowledge the report, assess severity and affected versions, coordinate a fix and disclosure, and credit the reporter when requested. Please allow a reasonable remediation window before public disclosure.

## Operational incidents

For a suspected compromise, first isolate the deployment according to your incident-response policy. Preserve application and proxy logs, audit records, the production environment file, and volume backups. Rotate exposed application, mail, SSO, target-database, and infrastructure credentials. Do not attach secrets or production data to a public GitHub issue.
