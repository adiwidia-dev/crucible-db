# Contributing to documentation

Documentation is part of the product. Update it in the same pull request as a user-visible behavior, policy, workflow, or deployment change.

## Write for the person doing the work

Every workflow page should state:

1. Who can use it and what they need first.
2. The exact steps in the application.
3. What the controls, warnings, and blocks mean.
4. The safe next action for success and failure.
5. Links to the policy or operational reference that governs the behavior.

Avoid implementation-only explanations in user pages. Put codebase and runtime detail in **Operate Crucible DB**.

## Screenshots

Screenshots belong in `docs/assets/screenshots/` and must use sanitized data. Capture them at the standard 1440 × 900 viewport with the documented browser workflow in [`docs/capture/README.md`](capture/README.md).

Update a screenshot when the described interface changes. Never capture credentials, personal data, production endpoints, database contents, session tokens, recovery codes, or internal incident information.

## Local preview

Create a local Python virtual environment, install the pinned documentation requirements, then start the development server:

```bash
python3 -m venv .venv-docs
. .venv-docs/bin/activate
pip install -r requirements-docs.txt
mkdocs serve
```

Run a strict build before opening a pull request:

```bash
mkdocs build --strict
```

The GitHub Actions documentation workflow performs the same build on pull requests and publishes the site only from `main`.

## Release verification

Before promoting `develop` to `main`, run `composer ci:check`, the production frontend build, the native race-enabled test suite, and `mkdocs build --strict`. For the Docker development stack, use `docker compose exec -T app composer ci:check`; PHPUnit pins the setup token in both environment and server variables, independently of the container's deployment token.

If a test fails, reproduce it individually and compare with the previous release using the same runtime and dependencies before changing application logic. Do not skip or weaken a failing test to complete a release.

Require successful application, database-matrix, native-proxy, and documentation checks on the promotion pull request. After merging, confirm the checks and GitHub Pages deployment on the exact `main` commit before creating its matching release tag. Supervise the tag-triggered publication workflow through image, CLI, signing, provenance, and Homebrew completion; then verify the published release description and live documentation version.

Current version labels and install examples must match the release. Historical changelog entries, versioned release notes, and dependency versions remain intact.
