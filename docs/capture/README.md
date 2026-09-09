# Documentation screenshot capture

Documentation screenshots must be repeatable and safe. Capture them from a local demo workspace, never from production or a workspace containing real credentials or customer data.

## Capture standard

- Browser: Chromium.
- Viewport: 1440 × 900.
- Browser zoom: 100 percent.
- Theme: default light theme.
- Data: synthetic connection names, users, SQL, and results only.
- Focus: one task or state per image.
- Location: `docs/assets/screenshots/`.

## Recommended workflow

1. Start an isolated local application instance backed by a dedicated SQLite database. Never seed a normal development, staging, or production workspace.
2. Run `php artisan migrate:fresh --force --no-interaction` against that dedicated database.
3. Run `php artisan db:seed --class=Database\\Seeders\\DocumentationSeeder --force` against the same database.
4. Install Chromium once with `npx playwright install chromium`.
5. Run `npm run docs:capture` to capture the public, requester, reviewer, and administrator pages.
6. For Native client documentation, use the seeded `Native Client: investigate checkout timing` request. The capture workflow produces `native-client-session.png` with the session overview, active DBeaver connection, local CLI command, and sanitized statement history only. Do not create or capture a temporary password, device code, or bearer token.
7. Save any additional named screenshots in `docs/assets/screenshots/` and reference them from the relevant guide.
8. Review every image for sensitive data before committing it.

The documentation seeder creates local-only synthetic accounts. The default password is `password`:

| Perspective | Account |
| --- | --- |
| Administrator | `admin@example.com` |
| Requester | `developer@example.com` |
| Reviewer | `reviewer@example.com` |
| Two-factor challenge | `twofactor@example.com` |

Override these only in the local shell with `DOCS_ADMIN_EMAIL`, `DOCS_REQUESTER_EMAIL`, `DOCS_REVIEWER_EMAIL`, `DOCS_TWO_FACTOR_EMAIL`, and `DOCS_DEMO_PASSWORD`.

Use `DOCS_CAPTURE_SCOPE=public`, `requester`, `reviewer`, `admin`, or `admin-details` to regenerate one portion of the initialized screenshot set. The default `all` scope captures every initialized perspective.

Initial-setup screenshots require a separate, migrated but uninitialized workspace in managed database mode. Set `DOCS_CAPTURE_SCOPE=setup` and make `DOCS_SETUP_TOKEN` match that instance's `CRUCIBLE_INITIAL_SETUP_TOKEN`. This scope captures the token gate and database-selection page. Set `DOCS_SETUP_PROVISION_DATABASE=true` only for an isolated disposable workspace when the capture should also save its SQLite selection.

After restarting that isolated instance on the saved selection, use `DOCS_CAPTURE_SCOPE=setup-owner` to recapture the owner and first target-connection forms. This scope creates the synthetic `admin@example.com` owner so it must never run against a reusable workspace. Run the normal seeders afterward if the same isolated database will supply the initialized screenshot set. `DOCS_CAPTURE_SCOPE=admin-database` regenerates only the managed Application Database page.

Invitation acceptance is captured when `DOCS_INVITATION_URL` contains the temporary signed URL for the synthetic `invited@example.com` fixture.

## Authentication state

Never commit a browser storage state. It contains session information. Keep it in `docs/capture/.auth/`, which is ignored by Git. The supplied capture command creates isolated browser contexts, signs in with local-only fixture accounts, and does not write a storage state.

## Screenshot review checklist

- [ ] The screenshot supports a specific instruction in the page.
- [ ] The target, user, SQL, and result content are synthetic.
- [ ] No URL, token, cookie, key, password, recovery code, or credential is visible.
- [ ] The active state and next action are visible.
- [ ] The image matches the current UI and uses the standard viewport.
