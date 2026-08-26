# Native Client Access Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deliver complete, production-ready PostgreSQL and MySQL Native Client Access through an official Crucible CLI while reusing Query Access approval, policy, session, notification, and audit semantics.

**Architecture:** Laravel remains the authoritative control plane and owns encrypted credentials, policy decisions, lease lifecycle, device authorization, statement authorization, and audit records. A separate Go module builds `crucible-proxy` and `crucible`; the proxy exposes one authenticated HTTPS/WebSocket tunnel, terminates PostgreSQL/MySQL protocols on private listeners, obtains short-lived control decisions through an HMAC-signed internal API, and never reads SQLite or `APP_KEY`.

**Tech Stack:** Laravel 13.23/PHP 8.5, Fortify 1.37, Inertia React 3.6, Wayfinder 0.1, Tailwind 4.3, Redis/Horizon, FrankenPHP/Octane, Go 1.25+ toolchain, pgx 5.10, go-mysql 1.16, coder/websocket 1.8, Cobra 1.10, go-redis 9.22, Prometheus client 1.24, PostgreSQL 14-18, MySQL 8.0/8.4.

---

## Execution rules

1. Follow Superpowers TDD for every behavior: write one failing test, run it and confirm the expected failure, implement the minimum behavior, rerun, refactor only while green.
2. Before each Laravel/Inertia/Tailwind/Wayfinder change, run Laravel Boost `search_docs` for the exact API involved.
3. Use Artisan `make:* --no-interaction` commands for Laravel classes and migrations, then edit the generated files.
4. Use Wayfinder-generated controller actions for every React-to-Laravel call; regenerate after route/controller changes.
5. Never expose protocol listener ports in production, persist plaintext credentials/tokens, log bound values/results, or give Go access to Laravel SQLite/`APP_KEY`.
6. Every task ends with its narrow verification and a focused conventional commit. Do not batch unrelated tasks.
7. Keep the implementation aligned with `docs/superpowers/specs/2026-08-25-native-client-access-design.md`; a behavior change requires updating the spec before code.

## Locked file structure

### Laravel control plane

- `app/Enums/AccessTransport.php`: `Browser` and `NativeProxy` transport values.
- `app/Enums/NativeProxy*.php`: lease, device authorization, connection, and disconnect states.
- `app/Models/NativeProxy*.php`: lease, device authorization, tunnel token, auth attempt, and connection persistence.
- `app/Services/NativeProxy/LeaseWorkflow.php`: idempotent lease creation, rotation, revoke, expiry.
- `app/Services/NativeProxy/DeviceAuthorizationWorkflow.php`: RFC 8628-style device flow.
- `app/Services/NativeProxy/ConnectionAdmission.php`: auth attempts, atomic limits, reservations, upstream configuration.
- `app/Services/NativeProxy/StatementWorkflow.php`: fail-closed authorization and terminal outcomes.
- `app/Services/NativeProxy/ProxyStatementPolicy.php`: shared native policy entry point.
- `app/Services/NativeProxy/PostgreSqlSessionCommandPolicy.php`: exact PostgreSQL allowlist.
- `app/Services/NativeProxy/MySqlSessionCommandPolicy.php`: exact MySQL allowlist and filtered SHOW decisions.
- `app/Http/Middleware/VerifyNativeProxyControlRequest.php`: HMAC, timestamp, nonce, and replay protection.
- `app/Http/Controllers/NativeProxy/*`: browser lease/credential/device UI.
- `app/Http/Controllers/Internal/NativeProxy/*`: private JSON control endpoints.
- `routes/native-proxy.php`: private control routes, loaded explicitly by `bootstrap/app.php`.
- `config/native_proxy.php`: validated feature, public URL, HMAC, timeout, and limit configuration.

### Go data plane and CLI

- `native/go.mod`: isolated Go module with pinned dependencies.
- `native/cmd/crucible/main.go`: CLI entry point.
- `native/cmd/crucible-proxy/main.go`: proxy entry point.
- `native/internal/cli/*`: commands, device flow, browser launch, loopback listener, JSON output.
- `native/internal/tunnel/*`: discovery, binary WebSocket bridge, close/error codes, limits.
- `native/internal/control/*`: canonical HMAC client and control DTOs.
- `native/internal/proxy/*`: server lifecycle, connection registry, limits, revocation, health, metrics.
- `native/internal/postgres/*`: PostgreSQL startup/auth/protocol/statement tracking/cancel behavior.
- `native/internal/mysql/*`: MySQL handshake/auth/commands/prepared statements/result handling.
- `native/internal/redact/*`: structured redaction helpers.
- `native/test/integration/*`: real Laravel/Redis/PostgreSQL/MySQL test harness.

### Deployment, CI, and documentation

- `Dockerfile.native`: non-root production proxy image.
- `.goreleaser.yaml`: signed multi-platform CLI archives/packages/checksums/SBOM metadata.
- `.github/workflows/native.yml`: Go unit/race/fuzz/static/integration/image gate.
- `.github/workflows/release-native.yml`: tag-only signed CLI artifacts.
- `compose.yaml`, `compose.production.yaml`: proxy service and private networking.
- `.docker/Caddyfile`, `.docker/production-supervisord.conf`: single-origin path routing and custom Octane/FrankenPHP startup.
- `.env.example`, `.env.production.example`, `docs/**`: configuration, user/admin/operator/security/compatibility references.

## Task 1: Establish the Go module and immutable protocol contracts

**Files:**

- Create: `native/go.mod`
- Create: `native/cmd/crucible/main.go`
- Create: `native/cmd/crucible-proxy/main.go`
- Create: `native/internal/version/version.go`
- Create: `native/internal/tunnel/protocol.go`
- Test: `native/internal/tunnel/protocol_test.go`

- [ ] **Step 1: Write the failing tunnel-version contract test**

```go
func TestNegotiateRejectsUnsupportedMajorVersion(t *testing.T) {
    _, err := tunnel.Negotiate(tunnel.Hello{Protocol: "crucible.tunnel.v2"})
    if !errors.Is(err, tunnel.ErrUnsupportedVersion) {
        t.Fatalf("expected unsupported version, got %v", err)
    }
}
```

- [ ] **Step 2: Run the test and verify RED**

Run: `cd native && go test ./internal/tunnel -run TestNegotiateRejectsUnsupportedMajorVersion -v`
Expected: FAIL because the module/package does not exist.

- [ ] **Step 3: Create the module with pinned dependencies**

```go
module github.com/adiwidia-dev/crucible-db/native

go 1.25.0

require (
    github.com/coder/websocket v1.8.15
    github.com/go-mysql-org/go-mysql v1.16.0
    github.com/jackc/pgx/v5 v5.10.0
    github.com/prometheus/client_golang v1.24.1
    github.com/redis/go-redis/v9 v9.22.0
    github.com/spf13/cobra v1.10.2
    github.com/xdg-go/scram v1.2.0
    github.com/google/go-cmp v0.7.0
)
```

Define `CurrentProtocol = "crucible.tunnel.v1"`, `Hello`, stable close codes, build version/revision/date variables, and minimal command entry points that return errors instead of calling `os.Exit` below testable command code.

- [ ] **Step 4: Run module and contract verification**

Run: `cd native && go mod tidy && go test ./internal/tunnel -v && go test ./...`
Expected: PASS and a clean `go.sum`.

- [ ] **Step 5: Commit**

```bash
git add native
git commit -m "feat(native): establish proxy and cli module"
```

## Task 2: Persist Query Access transport and native policy gates

**Files:**

- Create: `app/Enums/AccessTransport.php`
- Create: `database/migrations/*_add_native_proxy_transport_and_policy_fields.php`
- Modify: `app/Models/QueryRequest.php`
- Modify: `app/Models/DatabaseConnection.php`
- Modify: `app/Models/RoleDatabasePermission.php`
- Modify: `app/Models/RoleConnectionGroupPolicy.php`
- Modify: `app/Models/User.php`
- Modify: `app/Http/Requests/StoreRoleRequest.php`
- Modify: `app/Http/Requests/UpdateRoleRequest.php`
- Modify: `app/Http/Controllers/RoleController.php`
- Modify: `resources/js/pages/roles/form.tsx`
- Modify: `database/factories/QueryRequestFactory.php`
- Modify: `database/factories/DatabaseConnectionFactory.php`
- Modify: `database/factories/RoleDatabasePermissionFactory.php`
- Modify: `database/factories/RoleConnectionGroupPolicyFactory.php`
- Test: `tests/Feature/QueryAccessPolicyTest.php`
- Test: `tests/Feature/ConnectionGroupManagementTest.php`
- Test: `tests/Feature/AdminRoleManagementTest.php`

- [ ] **Step 1: Write failing model/default/backfill tests**

Add PHPUnit tests proving:

```php
$request = QueryRequest::factory()->create();
$this->assertSame(AccessTransport::Browser, $request->access_transport);

$connection = DatabaseConnection::factory()->create();
$this->assertFalse($connection->native_proxy_enabled);

$permission = RoleDatabasePermission::factory()->create([
    'access_mode' => AccessMode::Write,
    'query_access_mode' => AccessMode::Write,
    'native_proxy_access_mode' => AccessMode::Read,
]);
$this->assertSame(AccessMode::Read, $permission->native_proxy_access_mode);
```

Also prove direct policy precedence and most-restrictive group resolution include `native_proxy_access_mode`. Test `none`, `read`, and `write`; cap the effective native mode by Maximum access; and prove native mode is independent from `query_access_mode`. Add controller/request tests proving an administrator can set native mode on direct and group policy rows and non-admins cannot.

- [ ] **Step 2: Run tests and verify RED**

Run: `php artisan test --compact tests/Feature/QueryAccessPolicyTest.php tests/Feature/ConnectionGroupManagementTest.php tests/Feature/AdminRoleManagementTest.php`
Expected: FAIL because fields/enums do not exist.

- [ ] **Step 3: Generate and implement schema/model changes**

Run: `php artisan make:migration add_native_proxy_transport_and_policy_fields --no-interaction`

Migration requirements:

```php
$table->string('access_transport', 32)->default('browser')->index();
$table->boolean('native_proxy_enabled')->default(false);
$table->string('native_proxy_access_mode', 16)->default('none');
```

Add enum casts/fillable attributes and return `native_proxy_access_mode` from `User::effectiveDatabasePermission*`. Add `effectiveNativeProxyPermissionFor(DatabaseConnection $connection, QueryType $queryType)` using the existing ordered-role rules, direct-over-group precedence, most-restrictive group resolution, and Maximum access cap. Admin resolution returns `AccessMode::Write`; native mode never changes `query_access_mode` or `access_mode`.

Extend role Form Request rules and `RoleController` serialization/persistence for both direct and connection-group policies. In `resources/js/pages/roles/form.tsx`, add a **Native Client Access** dropdown beside **Query Access** with **Disabled**, **Read-only**, and **Read + write**. Disable options broader than Maximum access, preserve its value while editing, reduce it when Maximum access is lowered, and explain that native SQL is not known before approval. Keep Query Access and Native Client Access independently configurable.

- [ ] **Step 4: Verify GREEN and formatting**

Run: `vendor/bin/pint --dirty --format agent`
Run: `npm run types:check`
Run: `php artisan test --compact tests/Feature/QueryAccessPolicyTest.php tests/Feature/ConnectionGroupManagementTest.php tests/Feature/AdminRoleManagementTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app database resources/js/pages/roles/form.tsx tests/Feature/QueryAccessPolicyTest.php tests/Feature/ConnectionGroupManagementTest.php tests/Feature/AdminRoleManagementTest.php
git commit -m "feat: add native query access policy gates"
```

## Task 3: Normalize upstream TLS configuration for both engines

**Files:**

- Create: `app/Enums/DatabaseTlsMode.php`
- Create: `database/migrations/*_normalize_database_connection_tls_configuration.php`
- Modify: `app/Models/DatabaseConnection.php`
- Modify: `app/Http/Requests/StoreDatabaseConnectionRequest.php`
- Modify: `app/Http/Requests/UpdateDatabaseConnectionRequest.php`
- Modify: `app/Services/DatabaseQueryExecutor.php`
- Modify: `resources/js/pages/connections/form.tsx`
- Test: `tests/Feature/CrucibleMvpTest.php`
- Test: `tests/Feature/QueryExecutionSecurityTest.php`

- [ ] **Step 1: Write failing TLS validation and driver-option tests**

Cover disabled/preferred/required/verify-ca/verify-identity, certificate/key pairing, encrypted hidden client key, PostgreSQL `sslmode`, and MySQL PDO CA/certificate/key options. Prove `verify-identity` rejects a missing CA. Add administrator/non-admin tests for `native_proxy_enabled` and prove a disabled connection cannot be selected or admitted.

- [ ] **Step 2: Run narrow tests and verify RED**

Run: `php artisan test --compact tests/Feature/CrucibleMvpTest.php --filter=connection_tls`
Run: `php artisan test --compact tests/Feature/QueryExecutionSecurityTest.php --filter=tls`
Expected: FAIL for missing fields/behavior.

- [ ] **Step 3: Implement migration and request/model/executor changes**

Use `DatabaseTlsMode` values `disabled`, `preferred`, `required`, `verify_ca`, `verify_identity`. Preserve existing PostgreSQL `ssl_mode` values during migration, add encrypted `tls_client_key`, and hide it from serialization. Never accept `tls_skip_verify` in production configuration.

- [ ] **Step 4: Update the connection form using existing Crucible controls**

Show TLS fields progressively, keep certificate/key text out of Inertia history, never echo the stored key, and use Wayfinder actions already generated for connection store/update. Add a **Native Client Access** connection toggle with clear exposure/credential implications and serialize/persist it through both store/update Form Requests and `DatabaseConnectionController`.

- [ ] **Step 5: Verify**

Run: `vendor/bin/pint --dirty --format agent`
Run: `npm run types:check`
Run: `php artisan test --compact tests/Feature/CrucibleMvpTest.php tests/Feature/QueryExecutionSecurityTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app database resources/js/pages/connections tests
git commit -m "feat: normalize target database tls policy"
```

## Task 4: Validate the three request choices and one-target invariant server-side

**Files:**

- Modify: `app/Http/Requests/StoreQueryRequestRequest.php`
- Modify: `app/Http/Requests/UpdateQueryRequestRequest.php`
- Modify: `app/Services/QueryRequestWorkflow.php`
- Modify: `app/Http/Controllers/QueryRequestController.php`
- Modify: `app/Policies/QueryRequestPolicy.php`
- Test: `tests/Feature/QueryAccessPolicyTest.php`
- Test: `tests/Feature/QueryRequestBatchWorkflowTest.php`

- [ ] **Step 1: Write failing feature tests**

Prove:

- native transport is valid only with `request_kind=query_access`;
- exactly one connection is required;
- connection must be active and `native_proxy_enabled`;
- current role's effective `native_proxy_access_mode` must allow the requested read/write level;
- transport cannot change after creation;
- retry/renewal preserves transport but creates a fresh session later;
- browser Query Access still accepts up to ten targets;
- drafts remain Deployment Batch-only.

- [ ] **Step 2: Verify RED**

Run: `php artisan test --compact tests/Feature/QueryAccessPolicyTest.php tests/Feature/QueryRequestBatchWorkflowTest.php --filter=native`
Expected: FAIL because transport rules are absent.

- [ ] **Step 3: Implement explicit conditional rules**

Use `Rule::enum(AccessTransport::class)`, `Rule::requiredIf`, and an after-validation closure for cross-field invariants. Add workflow-level checks so internal callers cannot bypass Form Requests. Return field-specific errors for `access_transport`, `database_connection_ids`, and `requested_access_mode`.

- [ ] **Step 4: Verify GREEN**

Run: `vendor/bin/pint --dirty --format agent`
Run: `php artisan test --compact tests/Feature/QueryAccessPolicyTest.php tests/Feature/QueryRequestBatchWorkflowTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http app/Services app/Policies tests/Feature
git commit -m "feat: validate native client access requests"
```

## Task 5: Build the three-choice request creation and review presentation

**Files:**

- Modify: `resources/js/pages/query-requests/create.tsx`
- Modify: `resources/js/pages/query-requests/index.tsx`
- Modify: `resources/js/pages/query-requests/show.tsx`
- Modify: `resources/js/lib/crucible.ts`
- Modify: `app/Http/Controllers/QueryRequestController.php`
- Test: `tests/Feature/CrucibleMvpTest.php`

- [ ] **Step 1: Write failing Inertia response assertions**

Assert create props expose `native_proxy_enabled` and resolved `native_proxy_access_mode` per connection; list/show props expose `access_transport`; native request details contain one target, `requested_access_mode`, and no deployment preflight controls.

- [ ] **Step 2: Run and verify RED**

Run: `php artisan test --compact tests/Feature/CrucibleMvpTest.php --filter=native_client`
Expected: FAIL for missing props.

- [ ] **Step 3: Implement the React choice model**

Use a stable three-option selector with labels **Deployment Batch**, **Query Access**, and **Native Client Access**. Persist native choice as hidden `request_kind=query_access` and `access_transport=native_proxy`. Native form shows one connection, duration, title, reason, and a **Session access level** section matching the existing Query Access radio-card control. **Read-only** is available when effective native mode allows read; **Read + write** is enabled only when it allows write. Explain why write is unavailable, preserve the selected level only while valid, and submit `requested_access_mode`. Use existing buttons, connection combobox, status badges, focus treatment, spacing, dark mode, and responsive structure.

- [ ] **Step 4: Add review/list transport copy**

Display `Native client` adjacent to Query Access state, with explicit copy: “SQL is executed later from an approved native database client.” Keep target, level, duration, approval state, and next action together.

- [ ] **Step 5: Regenerate Wayfinder and verify frontend**

Run: `php artisan wayfinder:generate --with-form --no-interaction`
Run: `npm run types:check`
Run: `npm run lint:check`
Run: `npm run format:check`
Run: `php artisan test --compact tests/Feature/CrucibleMvpTest.php --filter=native_client`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/QueryRequestController.php resources/js tests/Feature/CrucibleMvpTest.php
git commit -m "feat: present native client access requests"
```

## Task 6: Create lease, token, authentication-attempt, and connection schema

**Files:**

- Create: `app/Enums/NativeProxyLeaseStatus.php`
- Create: `app/Enums/NativeProxyDeviceAuthorizationStatus.php`
- Create: `app/Enums/NativeProxyConnectionStatus.php`
- Create: `app/Models/NativeProxyLease.php`
- Create: `app/Models/NativeProxyDeviceAuthorization.php`
- Create: `app/Models/NativeProxyToken.php`
- Create: `app/Models/NativeProxyAuthAttempt.php`
- Create: `app/Models/NativeProxyConnection.php`
- Create: `database/migrations/*_create_native_proxy_control_tables.php`
- Create: `database/factories/NativeProxyLeaseFactory.php`
- Create: `database/factories/NativeProxyDeviceAuthorizationFactory.php`
- Create: `database/factories/NativeProxyTokenFactory.php`
- Create: `database/factories/NativeProxyAuthAttemptFactory.php`
- Create: `database/factories/NativeProxyConnectionFactory.php`
- Test: `tests/Feature/NativeProxyLeaseTest.php`

- [ ] **Step 1: Generate classes and write failing relationship/cast tests**

Run the appropriate `php artisan make:model --factory --no-interaction` commands and `php artisan make:test --phpunit NativeProxyLeaseTest --no-interaction`, then test ULIDs, hidden secret/token fields, enum casts, unique Query Session lease, foreign keys, token/device/auth-attempt relationships, and indexed active scopes.

- [ ] **Step 2: Run test and verify RED**

Run: `php artisan test --compact tests/Feature/NativeProxyLeaseTest.php`
Expected: FAIL before migration/model implementation.

- [ ] **Step 3: Implement the exact schema from the spec**

Use `HasUlids`, explicit `$fillable`, `#[Hidden]` or `$hidden` for encrypted secret/hash fields, encrypted cast for `protocol_auth_secret`, and cascade/restrict rules that preserve audit history. Add the unique `query_session_id` lease constraint and unique Go connection id.

- [ ] **Step 4: Verify schema and models**

Run: `vendor/bin/pint --dirty --format agent`
Run: `php artisan migrate:fresh --seed --no-interaction`
Run: `php artisan test --compact tests/Feature/NativeProxyLeaseTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Enums app/Models database tests/Feature/NativeProxyLeaseTest.php
git commit -m "feat: persist native proxy control state"
```

## Task 7: Implement idempotent lease creation, one-time reveal, rotation, and revocation

**Files:**

- Create: `app/Services/NativeProxy/LeaseWorkflow.php`
- Create: `app/Support/NativeProxyCredential.php`
- Create: `app/Http/Controllers/NativeProxy/LeaseController.php`
- Create: `app/Http/Requests/CreateNativeProxyCredentialsRequest.php`
- Create: `app/Http/Requests/RotateNativeProxyCredentialsRequest.php`
- Create: `app/Http/Requests/RevokeNativeProxyLeaseRequest.php`
- Modify: `routes/web.php`
- Modify: `app/Policies/QuerySessionPolicy.php`
- Test: `tests/Feature/NativeProxyLeaseTest.php`

- [ ] **Step 1: Write failing workflow tests**

Test one lease per native session, 32-byte random password, non-enumerable username, encrypted+hashed persistence, one no-store JSON response reveal, idempotency-key conflict behavior, rotation in place with incremented version, token/connection revocation, owner/admin authorization, inactive/browser session denial, audit events, and secret clearing on revoke/expiry. Assert plaintext is absent from Laravel session/flash storage, Inertia props/history, logs, database columns other than the encrypted cast, and every repeat response.

- [ ] **Step 2: Verify RED**

Run: `php artisan test --compact tests/Feature/NativeProxyLeaseTest.php`
Expected: FAIL because workflow/routes do not exist.

- [ ] **Step 3: Implement transactionally**

Core API:

```php
public function create(QuerySession $session, User $actor, string $idempotencyKey): IssuedNativeProxyCredential;
public function rotate(NativeProxyLease $lease, User $actor): IssuedNativeProxyCredential;
public function revoke(NativeProxyLease $lease, User $actor, string $reason): void;
public function expireDue(): int;
```

Use `lockForUpdate`, catch unique-key races by reloading the existing lease, and publish revocation after transaction commit. Return plaintext only from dedicated authenticated, CSRF-protected JSON create/rotate actions with `Cache-Control: no-store`; do not flash or redirect with credentials. Repeat create returns `409 credentials_already_created` and rotation invalidates old connections before returning the new one-time value.

- [ ] **Step 4: Verify and regenerate routes**

Run: `vendor/bin/pint --dirty --format agent`
Run: `php artisan wayfinder:generate --with-form --no-interaction`
Run: `php artisan test --compact tests/Feature/NativeProxyLeaseTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app routes tests/Feature/NativeProxyLeaseTest.php resources/js/actions resources/js/routes
git commit -m "feat: manage native proxy credentials"
```

## Task 8: Implement RFC 8628-style CLI device authorization

**Files:**

- Create: `app/Services/NativeProxy/DeviceAuthorizationWorkflow.php`
- Create: `app/Http/Controllers/NativeProxy/DeviceAuthorizationController.php`
- Create: `app/Http/Controllers/Internal/NativeProxy/DeviceAuthorizationController.php`
- Create: `app/Http/Requests/ApproveNativeProxyDeviceRequest.php`
- Create: `resources/js/pages/native-proxy/device-authorization.tsx`
- Modify: `routes/web.php`
- Modify: `routes/native-proxy.php`
- Test: `tests/Feature/NativeProxyDeviceAuthorizationTest.php`

- [ ] **Step 1: Write failing device-flow tests**

Cover 256-bit device codes, unambiguous human codes, five-minute expiry, five-second polling, `no-store`, pending/slow_down/denied/expired/consumed semantics, one-time atomic token issue, owner-only browser approval, disabled user/session/lease denial, maximum three active device tokens, rate limits, and audit events.

- [ ] **Step 2: Verify RED**

Run: `php artisan test --compact tests/Feature/NativeProxyDeviceAuthorizationTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement workflow state transitions**

```php
public function begin(NativeProxyLease $lease, DeviceMetadata $metadata): DeviceAuthorizationChallenge;
public function approve(NativeProxyDeviceAuthorization $authorization, User $actor): void;
public function deny(NativeProxyDeviceAuthorization $authorization, User $actor): void;
public function poll(string $deviceCode): DeviceTokenResult;
```

Hash device/user codes with SHA-256, compare using constant-time methods, transactionally consume approved codes, and never issue refresh tokens.

- [ ] **Step 4: Implement the confirmation UI**

Show matching code, connection, protocol, access level, CLI device metadata, and expiry. Use explicit Approve/Deny buttons, accessible status, no decorative provider UI, and normal Fortify `auth`/`verified` browser middleware.

- [ ] **Step 5: Verify**

Run: `vendor/bin/pint --dirty --format agent`
Run: `php artisan wayfinder:generate --with-form --no-interaction`
Run: `npm run types:check && npm run lint:check && npm run format:check`
Run: `php artisan test --compact tests/Feature/NativeProxyDeviceAuthorizationTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app routes resources/js tests/Feature/NativeProxyDeviceAuthorizationTest.php
git commit -m "feat: authorize native cli devices"
```

## Task 9: Secure the internal control API with canonical HMAC requests

**Files:**

- Create: `config/native_proxy.php`
- Create: `app/Http/Middleware/VerifyNativeProxyControlRequest.php`
- Create: `routes/native-proxy.php`
- Modify: `bootstrap/app.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Modify: `.env.example`
- Modify: `.env.production.example`
- Test: `tests/Feature/NativeProxyControlAuthenticationTest.php`

- [ ] **Step 1: Write failing HMAC and replay tests**

Use canonical payload:

```text
METHOD\nPATH\nTIMESTAMP\nREQUEST_ID\nSHA256_HEX_BODY
```

Test correct signature, bad signature, stale/future timestamp, duplicate request id, body/path/method tampering, missing feature/secret config, constant-time compare, generic 401 JSON, and Redis nonce expiration. Include route-level proof that `/tunnels/authorize` cannot be called without the control middleware.

- [ ] **Step 2: Verify RED**

Run: `php artisan test --compact tests/Feature/NativeProxyControlAuthenticationTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement middleware and private route registration**

Register `routes/native-proxy.php` under `/internal/native-proxy/v1` without web sessions/CSRF, attach `native-proxy-control` and Redis-backed rate limiting, and ensure the production reverse proxy has no public route to it.

- [ ] **Step 4: Verify GREEN**

Run: `vendor/bin/pint --dirty --format agent`
Run: `php artisan test --compact tests/Feature/NativeProxyControlAuthenticationTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app bootstrap config routes .env.example .env.production.example tests/Feature/NativeProxyControlAuthenticationTest.php
git commit -m "feat: authenticate native proxy control calls"
```

## Task 10: Build the Go HMAC control client and public discovery/device gateway

**Files:**

- Create: `native/internal/control/client.go`
- Create: `native/internal/control/signature.go`
- Create: `native/internal/control/types.go`
- Create: `native/internal/gateway/server.go`
- Test: `native/internal/control/signature_test.go`
- Test: `native/internal/gateway/server_test.go`

- [ ] **Step 1: Write failing signature parity and gateway tests**

Use PHP fixture vectors checked into `native/internal/control/testdata/signatures.json`. Prove canonical signatures match Laravel, request ids are unique, retries never reuse nonces, responses are size-limited, discovery advertises the fixed same-origin `/.well-known/crucible-native-client.json` and `/native-tunnel/v1/*` paths, device endpoints forward only permitted fields, tunnel authorization forwards bearer hash/lease/device/protocol/proxy/connection identity before upgrade, and upstream errors are sanitized.

- [ ] **Step 2: Verify RED**

Run: `cd native && go test ./internal/control ./internal/gateway -v`
Expected: FAIL.

- [ ] **Step 3: Implement the client/gateway**

Use explicit HTTP client timeouts, no automatic retry for mutating requests without idempotency ids, `Cache-Control: no-store` for discovery/device responses, and `httptest`-friendly dependency injection.

- [ ] **Step 4: Verify GREEN and race safety**

Run: `cd native && gofmt -w . && go test -race ./internal/control ./internal/gateway`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add native
git commit -m "feat(native): add control client and cli gateway"
```

## Task 11: Implement the cross-platform CLI device flow

**Files:**

- Create: `native/internal/cli/root.go`
- Create: `native/internal/cli/connect.go`
- Create: `native/internal/cli/device.go`
- Create: `native/internal/cli/browser.go`
- Create: `native/internal/cli/output.go`
- Test: `native/internal/cli/connect_test.go`
- Test: `native/internal/cli/device_test.go`

- [ ] **Step 1: Write failing command/device tests**

Test required `--server`/`--lease`, HTTPS-only server except explicit test mode, discovery fetched from the same `--server` origin, fixed path/version bounds, browser-open fallback, printed user code, polling interval/slow_down, cancellation signals, token only in memory, `--json` secret-free events, rejection of every non-loopback listen address, local-port conflict errors, version output, and shell completion.

- [ ] **Step 2: Verify RED**

Run: `cd native && go test ./internal/cli -v`
Expected: FAIL.

- [ ] **Step 3: Implement Cobra commands**

```text
crucible connect --server URL --lease ULID [--listen 127.0.0.1:PORT] [--no-browser] [--json] [--ca-file FILE]
crucible version
crucible completion bash|zsh|fish|powershell
```

Keep `RunE` dependencies injectable and return stable exit categories: usage 2, authorization 10, local bind 20, TLS/network 30, revoked/expired 40, protocol/tunnel 50.

- [ ] **Step 4: Verify**

Run: `cd native && gofmt -w . && go test -race ./internal/cli && go vet ./...`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add native
git commit -m "feat(native): add crucible cli authorization flow"
```

## Task 12: Implement binary WebSocket tunnel and loopback forwarding

**Files:**

- Create: `native/internal/tunnel/server.go`
- Create: `native/internal/tunnel/client.go`
- Create: `native/internal/tunnel/bridge.go`
- Create: `native/internal/tunnel/limits.go`
- Modify: `native/internal/cli/connect.go`
- Test: `native/internal/tunnel/bridge_test.go`
- Test: `native/internal/tunnel/server_test.go`

- [ ] **Step 1: Write failing bridge tests**

Prove one WebSocket per local TCP connection, loopback-only enforcement with no override, bearer/header metadata, Laravel tunnel authorization before `websocket.Accept`, rejection after restart/revocation/policy change, binary-only frames, no compression, 10 MiB messages, 32 MiB bounded queues, backpressure, ping/pong, idle/absolute timeout, half-close, stable close codes, concurrent clients, and context cancellation without goroutine leaks.

- [ ] **Step 2: Verify RED**

Run: `cd native && go test ./internal/tunnel -v`
Expected: FAIL.

- [ ] **Step 3: Implement using coder/websocket**

Serve the public bridge at `/native-tunnel/v1/tunnel/{lease}`. Call the internal `POST /tunnels/authorize` control endpoint and require an allow response bound to the URL lease and presented token before `websocket.Accept`. Set `CompressionMode: websocket.CompressionDisabled`, `SetReadLimit`, require `crucible.tunnel.v1`, reject text frames, and copy in both directions through bounded buffers. The CLI derives PostgreSQL/MySQL default local ports from same-origin discovery but accepts an available loopback override.

- [ ] **Step 4: Verify race/leak behavior**

Run: `cd native && go test -race ./internal/tunnel -count=20`
Expected: PASS with no race/leak/timeouts.

- [ ] **Step 5: Commit**

```bash
git add native
git commit -m "feat(native): tunnel database clients over websocket"
```

## Task 13: Implement proxy process lifecycle, registry, health, limits, and metrics

**Files:**

- Create: `native/internal/proxy/config.go`
- Create: `native/internal/proxy/server.go`
- Create: `native/internal/proxy/registry.go`
- Create: `native/internal/proxy/health.go`
- Create: `native/internal/proxy/metrics.go`
- Create: `native/internal/redact/redact.go`
- Modify: `native/cmd/crucible-proxy/main.go`
- Test: `native/internal/proxy/server_test.go`
- Test: `native/internal/redact/redact_test.go`

- [ ] **Step 1: Write failing lifecycle/security tests**

Test loopback PostgreSQL/MySQL listeners, public gateway listener, readiness dependencies, global/user/lease limits, 15-minute idle timeout, graceful drain, forced cancellation, Redis subscription loss, redaction of password/token/SQL values, bounded metric labels, and nonzero config validation.

- [ ] **Step 2: Verify RED**

Run: `cd native && go test ./internal/proxy ./internal/redact -v`
Expected: FAIL.

- [ ] **Step 3: Implement process ownership**

Use `signal.NotifyContext`, one connection registry, atomic readiness, Prometheus `/metrics`, `/healthz`, `/readyz`, structured `slog`, and no package globals carrying request/lease state.

- [ ] **Step 4: Verify**

Run: `cd native && gofmt -w . && go test -race ./internal/proxy ./internal/redact -count=10`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add native
git commit -m "feat(native): manage proxy process lifecycle"
```

## Task 14: Implement handshake material and atomic Laravel connection admission

**Files:**

- Create: `app/Services/NativeProxy/ConnectionAdmission.php`
- Create: `app/Http/Controllers/Internal/NativeProxy/AuthMaterialController.php`
- Create: `app/Http/Controllers/Internal/NativeProxy/TunnelAuthorizationController.php`
- Create: `app/Http/Controllers/Internal/NativeProxy/ConnectionController.php`
- Create: `app/Http/Controllers/Internal/NativeProxy/ConnectionHeartbeatController.php`
- Create: `app/Http/Requests/Internal/NativeProxy/*`
- Modify: `routes/native-proxy.php`
- Test: `tests/Feature/NativeProxyConnectionAdmissionTest.php`

- [ ] **Step 1: Write failing confused-deputy/race tests**

Cover tunnel bearer hashing and constant-time lookup, token/lease/device/proxy/protocol/version/connection binding, token expiry/revocation, current policy recheck, denial before upgrade, cross-lease username denial, `no-store`, 15-second attempt expiry, single-use consumption, concurrent limit reservation, reservation cleanup, upstream credential release only after reservation, current role/user/connection/session checks, secret redaction, idempotent close, and heartbeat continue/revoke responses after every session/user/direct-policy/group-policy/connection/workspace-policy change.

- [ ] **Step 2: Verify RED**

Run: `php artisan test --compact tests/Feature/NativeProxyConnectionAdmissionTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement transactional APIs**

```php
public function issueAuthMaterial(AuthMaterialRequestData $data): AuthMaterialResult;
public function authorizeTunnel(TunnelAuthorizationData $data): AuthorizedTunnel;
public function authorizeConnection(ConsumeAuthAttemptData $data): AdmittedConnection;
public function markAuthenticated(NativeProxyConnection $connection): void;
public function heartbeat(NativeProxyConnection $connection): ConnectionHeartbeatDecision;
public function close(NativeProxyConnection $connection, string $reason): void;
```

Never return target configuration from auth-material. Clear local plaintext variables after response construction and use Laravel encrypted casts only for persistence.

- [ ] **Step 4: Verify**

Run: `vendor/bin/pint --dirty --format agent`
Run: `php artisan test --compact tests/Feature/NativeProxyConnectionAdmissionTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app routes tests/Feature/NativeProxyConnectionAdmissionTest.php
git commit -m "feat: admit native proxy connections atomically"
```

## Task 15: Implement exact PostgreSQL native session-command policy

**Files:**

- Create: `app/Services/NativeProxy/ProxyStatementDecision.php`
- Create: `app/Services/NativeProxy/ProxyStatementPolicy.php`
- Create: `app/Services/NativeProxy/PostgreSqlSessionCommandPolicy.php`
- Refactor: `app/Services/QueryGuard.php`
- Test: `tests/Unit/PostgreSqlSessionCommandPolicyTest.php`
- Test: `tests/Feature/QueryExecutionSecurityTest.php`

- [ ] **Step 1: Write table-driven failing policy tests**

Include every allowed PostgreSQL command/clause/value from spec section 15 and explicit denials for role/session authorization, `DISCARD ALL`, `LISTEN/NOTIFY`, SQL prepare/execute, copy, replication, function calls, read-only disable, oversized timeout, multi-statement, malformed identifiers, and unknown SET/RESET forms.

- [ ] **Step 2: Verify RED**

Run: `php artisan test --compact tests/Unit/PostgreSqlSessionCommandPolicyTest.php`
Expected: FAIL.

- [ ] **Step 3: Extract shared lexical primitives without changing existing behavior**

Expose safe `QueryGuard` normalization/comment/string scanning and top-level family classification through focused methods. Keep Deployment Batch/browser tests green before adding native rules.

- [ ] **Step 4: Implement PostgreSQL decision object**

Return normalized template, query type/family, command kind, transaction effect, timeout cap, allow/block code, and safe message. Deny unknown syntax by default.

- [ ] **Step 5: Verify existing and new policy suites**

Run: `vendor/bin/pint --dirty --format agent`
Run: `php artisan test --compact tests/Unit/PostgreSqlSessionCommandPolicyTest.php tests/Feature/QueryExecutionSecurityTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Services tests/Unit tests/Feature/QueryExecutionSecurityTest.php
git commit -m "feat: govern postgresql native session commands"
```

## Task 16: Implement exact MySQL native session-command policy and SHOW filtering

**Files:**

- Create: `app/Services/NativeProxy/MySqlSessionCommandPolicy.php`
- Modify: `app/Services/NativeProxy/ProxyStatementPolicy.php`
- Test: `tests/Unit/MySqlSessionCommandPolicyTest.php`

- [ ] **Step 1: Write table-driven failing tests**

Cover every permitted transaction, SET, USE, SHOW, DESCRIBE, and metadata form in spec section 15. Deny all other SHOW variants, global/persistent variables, password/role/user operations, locks, flush/reset/kill, user-variable executable SQL, read-only off, local infile, replication, multi-statements, malformed qualifications, and cross-database identifiers.

- [ ] **Step 2: Verify RED**

Run: `php artisan test --compact tests/Unit/MySqlSessionCommandPolicyTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement decisions including result filtering**

Mark `SHOW DATABASES|SCHEMAS` with `filter=lease_databases`; return allowed variable/timeout constraints and exact database qualification. Unknown variants return a stable block code.

- [ ] **Step 4: Verify**

Run: `vendor/bin/pint --dirty --format agent`
Run: `php artisan test --compact tests/Unit/MySqlSessionCommandPolicyTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/NativeProxy tests/Unit/MySqlSessionCommandPolicyTest.php
git commit -m "feat: govern mysql native session commands"
```

## Task 17: Persist fail-closed native statement authorization and outcomes

**Files:**

- Create: `database/migrations/*_add_native_proxy_metadata_to_query_execution_tables.php`
- Modify: `app/Models/QuerySessionQuery.php`
- Modify: `app/Models/QueryExecution.php`
- Create: `app/Services/NativeProxy/StatementWorkflow.php`
- Create: `app/Http/Controllers/Internal/NativeProxy/StatementController.php`
- Modify: `routes/native-proxy.php`
- Test: `tests/Feature/NativeProxyStatementWorkflowTest.php`

- [ ] **Step 1: Write failing authorization/privacy/idempotency tests**

Prove template validation creates no running QuerySessionQuery/QueryExecution for allowed prepared templates, blocked templates create only a terminal blocked audit, every actual execution authorization creates exactly one running QuerySessionQuery/QueryExecution before forwarding, repeated prepared execution creates one record per execution, bound values/results never persist, template/fingerprint/type/count/formats do, completion is idempotent, success/failure/blocked audits are accurate, unknown connection/lease/policy fails closed, stale running statements close during cleanup, and row counts/tags/errors are redacted and bounded.

- [ ] **Step 2: Verify RED**

Run: `php artisan test --compact tests/Feature/NativeProxyStatementWorkflowTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement workflow/control endpoints**

```php
public function validateTemplate(NativeProxyConnection $connection, NativeStatementTemplate $template): ProxyStatementDecision;
public function authorizeExecution(NativeProxyConnection $connection, NativeStatementData $data): AuthorizedNativeStatement;
public function complete(QuerySessionQuery $statement, NativeStatementOutcome $outcome): void;
```

Use transactions, connection ownership checks, current policy on every call, SHA-256 normalized-template fingerprints, bounded error fields, and no sample rows. `validateTemplate` never returns a statement authorization id for allowed templates; only `authorizeExecution` returns the id required before forwarding.

- [ ] **Step 4: Verify**

Run: `vendor/bin/pint --dirty --format agent`
Run: `php artisan test --compact tests/Feature/NativeProxyStatementWorkflowTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app database routes tests/Feature/NativeProxyStatementWorkflowTest.php
git commit -m "feat: audit native proxy statements"
```

## Task 18: Implement PostgreSQL startup, SCRAM, and simple Query protocol

**Files:**

- Create: `native/internal/postgres/server.go`
- Create: `native/internal/postgres/startup.go`
- Create: `native/internal/postgres/auth.go`
- Create: `native/internal/postgres/upstream.go`
- Create: `native/internal/postgres/simple.go`
- Test: `native/internal/postgres/startup_test.go`
- Test: `native/internal/postgres/simple_test.go`

- [ ] **Step 1: Write failing real-protocol tests**

Use pgx/psql-compatible clients against `net.Pipe` and PostgreSQL fixture to prove protocol 3.0/3.2 negotiation, SSLRequest receives `N` and continues over loopback/tunnel, SCRAM success/generic failure, exact lease database, application name sanitization, tunnel-lease binding, upstream TLS modes, read-only session initialization, statement authorize-before-forward, response/tag/error forwarding, and clean terminate.

- [ ] **Step 2: Verify RED**

Run: `cd native && go test ./internal/postgres -run 'TestStartup|TestSimple' -v`
Expected: FAIL.

- [ ] **Step 3: Implement startup/auth with handshake-scoped secret**

Use pgx v5 `pgproto3`, `xdg-go/scram`, auth-attempt control calls, constant-time generic failures, and overwrite/discard secret byte slices immediately after SCRAM completion.

- [ ] **Step 4: Implement simple-query forwarding**

Reject multi-statements/unsupported operations from Laravel decision, forward only after statement id is returned, apply upstream read-only/timeouts, stream results without storing rows, and complete the statement with command tag/row count/error.

- [ ] **Step 5: Verify**

Run: `cd native && gofmt -w . && go test -race ./internal/postgres`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add native
git commit -m "feat(native): proxy postgresql simple queries"
```

## Task 19: Complete PostgreSQL extended protocol, cancellation, and state limits

**Files:**

- Create: `native/internal/postgres/extended.go`
- Create: `native/internal/postgres/statements.go`
- Create: `native/internal/postgres/cancel.go`
- Modify: `native/internal/postgres/server.go`
- Test: `native/internal/postgres/extended_test.go`
- Test: `native/internal/postgres/cancel_test.go`
- Fuzz: `native/internal/postgres/fuzz_test.go`

- [ ] **Step 1: Write failing Parse/Bind/Execute tests**

Cover named/unnamed statements and portals, re-prepare, Describe, Execute max rows, Flush/Sync, Close, parameter OID/format metadata without values, `validateTemplate` at Parse with no running audit record, `authorizeExecution` at every Execute with exactly one terminal record, 256 limits, transaction state, pipelined errors, and disconnect cleanup.

- [ ] **Step 2: Write failing CancelRequest tests**

Prove proxy key mapping, variable-length PostgreSQL 18 secret keys, correct upstream cancellation, wrong-key silence, post-cancel audit outcome, and mapping cleanup.

- [ ] **Step 3: Verify RED**

Run: `cd native && go test ./internal/postgres -run 'TestExtended|TestCancel' -v`
Expected: FAIL.

- [ ] **Step 4: Implement extended/cancel state machines and explicit unsupported messages**

Reject replication startup, COPY, function-call protocol, unsupported versions/messages, and message-limit violations with protocol-correct errors.

- [ ] **Step 5: Add fuzz seeds and verify**

Run: `cd native && go test -race ./internal/postgres`
Run: `cd native && go test ./internal/postgres -run=^$ -fuzz=FuzzFrontendMessage -fuzztime=30s`
Expected: PASS/no crash.

- [ ] **Step 6: Commit**

```bash
git add native/internal/postgres
git commit -m "feat(native): complete postgresql proxy protocol"
```

## Task 20: Implement MySQL handshake, TLS authentication, database selection, and COM_QUERY

**Files:**

- Create: `native/internal/mysql/server.go`
- Create: `native/internal/mysql/auth.go`
- Create: `native/internal/mysql/upstream.go`
- Create: `native/internal/mysql/handler.go`
- Create: `native/internal/mysql/results.go`
- Test: `native/internal/mysql/auth_test.go`
- Test: `native/internal/mysql/query_test.go`

- [ ] **Step 1: Write failing MySQL 8 tests**

Prove caching_sha2 challenge/RSA authentication inside the tunnel without advertising `CLIENT_SSL`, generic failures, exact lease database, sanitized attributes, unsupported capability removal, upstream TLS verification, read-only initialization, `COM_INIT_DB`, `COM_QUERY` authorize-before-execute, autocommit/transaction status, result metadata/warnings/errors/tags, filtered SHOW DATABASES, and quit/rollback.

- [ ] **Step 2: Verify RED**

Run: `cd native && go test ./internal/mysql -run 'TestAuth|TestQuery' -v`
Expected: FAIL.

- [ ] **Step 3: Implement with go-mysql server/client packages**

Provide a concurrent `AuthenticationHandler` that obtains handshake material bound to the tunnel lease, performs `caching_sha2_password` challenge/RSA authentication over loopback inside the TLS WebSocket, and consumes the auth attempt during atomic admission. Implement a Handler that delegates policy to Laravel and converts upstream results without retaining row values after forwarding.

- [ ] **Step 4: Verify against MySQL 8.4 fixture**

Run: `cd native && gofmt -w . && go test -race ./internal/mysql`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add native/internal/mysql
git commit -m "feat(native): proxy mysql query protocol"
```

## Task 21: Complete MySQL prepared statements, status, and denied capabilities

**Files:**

- Create: `native/internal/mysql/statements.go`
- Modify: `native/internal/mysql/handler.go`
- Modify: `native/internal/mysql/server.go`
- Test: `native/internal/mysql/statements_test.go`
- Fuzz: `native/internal/mysql/fuzz_test.go`

- [ ] **Step 1: Write failing prepared-statement tests**

Cover prepare metadata through `validateTemplate` without a running audit, execute values forwarded but never audited through one `authorizeExecution` record per call, reset/close, repeated execution policy recheck, 256 statement limit, long data rejection or bounded handling, transaction/autocommit status, and upstream errors.

- [ ] **Step 2: Write failing unsupported-capability tests**

Prove compression, multi-statements, local infile, replication/binlog commands, change user, unsupported COM commands, oversized packets, unsafe SHOW forms, and cross-database operations are denied.

- [ ] **Step 3: Verify RED**

Run: `cd native && go test ./internal/mysql -run 'TestPrepared|TestUnsupported' -v`
Expected: FAIL.

- [ ] **Step 4: Implement and fuzz**

Run: `cd native && gofmt -w . && go test -race ./internal/mysql`
Run: `cd native && go test ./internal/mysql -run=^$ -fuzz=FuzzCommandPacket -fuzztime=30s`
Expected: PASS/no crash.

- [ ] **Step 5: Commit**

```bash
git add native/internal/mysql
git commit -m "feat(native): complete mysql proxy protocol"
```

## Task 22: Enforce heartbeat, revocation, expiry, rotation, and shutdown propagation

**Files:**

- Create: `app/Events/NativeProxyLeaseRevoked.php`
- Create: `app/Console/Commands/ExpireNativeProxyLeases.php`
- Create: `app/Console/Commands/PruneNativeProxyState.php`
- Modify: `routes/console.php`
- Modify: `app/Services/NativeProxy/LeaseWorkflow.php`
- Modify: `app/Services/NativeProxy/ConnectionAdmission.php`
- Modify: `app/Services/QuerySessionWorkflow.php`
- Modify: `app/Http/Controllers/UserRoleController.php`
- Modify: `app/Http/Controllers/DatabaseConnectionController.php`
- Modify: `app/Http/Controllers/RoleController.php`
- Modify: `app/Http/Controllers/ConnectionGroupController.php`
- Modify: `app/Services/ApplicationSettings.php`
- Modify: `native/internal/proxy/registry.go`
- Create: `native/internal/proxy/revocation.go`
- Test: `tests/Feature/NativeProxyRevocationTest.php`
- Test: `native/internal/proxy/revocation_test.go`

- [ ] **Step 1: Write failing Laravel revocation matrix tests**

Cover session end/expiry, request cancellation, user disable, user role assignment/priority change, role direct-policy change, role connection-group-policy change, connection-group membership change, workspace SQL-family policy change, connection deactivate/delete, credential rotate, lease revoke, Redis publish after commit, heartbeat current-policy denial even when Redis notification is missed, auth-attempt/token secret cleanup, and idempotency.

- [ ] **Step 2: Write failing Go five-second propagation tests**

Use a fake clock/control service to prove two-second heartbeat, Redis best-effort immediate close, control/Redis outage fail-closed, active query cancellation, transaction rollback by socket close, and bounded shutdown drain.

- [ ] **Step 3: Verify RED**

Run: `php artisan test --compact tests/Feature/NativeProxyRevocationTest.php`
Run: `cd native && go test ./internal/proxy -run TestRevocation -v`
Expected: FAIL.

- [ ] **Step 4: Implement after-commit revocation and scheduler**

Schedule expiry every minute for durable cleanup while Go heartbeat enforces exact socket expiry. Wire `UserRoleController`, `RoleController`, `ConnectionGroupController`, database connection mutations, and workspace policy mutations to publish affected lease ids only after commit. Ensure affected-lease queries avoid N+1 work. Schedule daily pruning of device authorizations, token hashes, consumed/expired auth attempts, abandoned reservations, and stale running statements after 30 days while preserving connection/statement audit retention.

- [ ] **Step 5: Verify**

Run: `vendor/bin/pint --dirty --format agent`
Run: `php artisan test --compact tests/Feature/NativeProxyRevocationTest.php`
Run: `cd native && go test -race ./internal/proxy -count=20`
Expected: PASS and measured propagation <=5 seconds.

- [ ] **Step 6: Commit**

```bash
git add app routes tests/Feature/NativeProxyRevocationTest.php native/internal/proxy
git commit -m "feat: revoke native database access immediately"
```

## Task 23: Build the Native Client session UI, one-time credentials, connections, and statement history

**Files:**

- Modify: `app/Http/Controllers/QuerySessionController.php`
- Create: `app/Http/Controllers/NativeProxy/ConnectionController.php`
- Modify: `resources/js/pages/query-sessions/show.tsx`
- Create: `resources/js/components/native-proxy/credential-reveal.tsx`
- Create: `resources/js/components/native-proxy/cli-instructions.tsx`
- Create: `resources/js/components/native-proxy/connection-list.tsx`
- Create: `resources/js/components/native-proxy/statement-history.tsx`
- Test: `tests/Feature/NativeProxySessionPageTest.php`

- [ ] **Step 1: Write failing page/authorization tests**

Assert owner/admin visibility, reviewer-safe audit visibility without credentials, browser session unchanged, one-time plaintext only in the dedicated no-store JSON response, CLI discovery/version/download props, connection/history pagination, rotate/revoke/end permissions, expiry countdown data, and no bound values/results. Assert the plaintext never appears in Inertia page props, remembered history, session/flash data, logs, or repeat requests.

- [ ] **Step 2: Verify RED**

Run: `php artisan test --compact tests/Feature/NativeProxySessionPageTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement stable operational layout**

Keep target/access/expiry/actions together; show credential creation/reveal inline, not in nested cards; use copy buttons with accessible success announcements; show install tabs for supported OS artifacts and the secret-free `crucible connect` command; poll only lease/connections/history props using Inertia v3 partial reloads. Use `useHttp(...).dontRemember('password')` for create/rotate JSON actions, store plaintext only in component memory, and clear it on copy-dismiss, navigation, visibility timeout, rotation, or unmount. Generated DBeaver/CLI/JDBC settings use localhost, explicitly disable database-protocol TLS on the local hop, and explain that the outbound Crucible WebSocket plus independently configured upstream TLS provide transport protection.

- [ ] **Step 4: Verify frontend/backend**

Run: `php artisan wayfinder:generate --with-form --no-interaction`
Run: `npm run types:check && npm run lint:check && npm run format:check`
Run: `vendor/bin/pint --dirty --format agent`
Run: `php artisan test --compact tests/Feature/NativeProxySessionPageTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http resources/js tests/Feature/NativeProxySessionPageTest.php
git commit -m "feat: operate native client sessions"
```

## Task 24: Add notifications, audit filters, and operator proxy health

**Files:**

- Modify: `app/Services/NotificationDispatcher.php`
- Modify: `app/Services/AuditLogger.php`
- Modify: `app/Services/DashboardOverview.php`
- Create: `app/Services/NativeProxy/ProxyHealth.php`
- Modify: `app/Http/Controllers/DashboardController.php`
- Modify: `app/Http/Controllers/AuditLogController.php`
- Modify: `resources/js/pages/dashboard.tsx`
- Modify: `resources/js/pages/audit-logs/index.tsx`
- Test: `tests/Feature/NotificationDeliveryTest.php`
- Test: `tests/Feature/DashboardTest.php`
- Test: `tests/Feature/CrucibleMvpTest.php`

- [ ] **Step 1: Write failing operational tests**

Cover requester notifications for credentials/revoke/expiry/failure, operational alerts for proxy unhealthy/version mismatch/repeated auth failures, audit filters/CSV actions for native events, dashboard readiness/version/instances/counts, and safe payloads with no secrets/parameters/rows.

- [ ] **Step 2: Verify RED**

Run the three feature files with `--compact`; expect missing behavior failures.

- [ ] **Step 3: Implement bounded health and notification behavior**

Proxy health calls use short timeouts/cached last-known state and never block ordinary pages. Notifications link to the request/session and use existing preference/operational-recipient conventions.

- [ ] **Step 4: Verify**

Run: `vendor/bin/pint --dirty --format agent`
Run: `npm run types:check`
Run: `php artisan test --compact tests/Feature/NotificationDeliveryTest.php tests/Feature/DashboardTest.php tests/Feature/CrucibleMvpTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app resources/js tests/Feature
git commit -m "feat: surface native proxy operations"
```

## Task 25: Add development Compose services and real end-to-end harness

**Files:**

- Modify: `compose.yaml`
- Modify: `.docker/Caddyfile`
- Create: `native/test/integration/harness_test.go`
- Create: `native/test/integration/postgres_test.go`
- Create: `native/test/integration/mysql_test.go`
- Create: `tests/Feature/NativeProxyControlContractTest.php`
- Modify: `phpunit.xml`

- [ ] **Step 1: Write failing cross-language contract and E2E tests**

Test same-origin discovery/device/tunnel routing through the application Caddy endpoint, synthetic auth, read-only/write, prepared statements, audit metadata, cancellation, rotation, expiry, cross-lease denial, Laravel/Redis/proxy/target outages, reconnect, and target credential lacking user-creation privileges.

- [ ] **Step 2: Verify RED with services running**

Run: `docker compose up -d --build app redis target-postgres target-mysql native-proxy`
Run: `php artisan test --compact tests/Feature/NativeProxyControlContractTest.php`
Run: `cd native && go test -tags=integration ./test/integration -v`
Expected: FAIL before harness/service wiring.

- [ ] **Step 3: Implement development service configuration**

Build `native-proxy` from local source, keep tunnel port 8081 and native listeners private, and route `/.well-known/crucible-native-client.json` plus `/native-tunnel/*` through the existing application Caddy port. Preserve WebSocket upgrade headers, disable response buffering for tunnel traffic, and leave all other paths on Laravel. Provide the dedicated control secret, health dependencies, and fixture users that can query/write only the target database and cannot create users/roles.

- [ ] **Step 4: Verify full E2E matrix**

Run the PHP contract test and Go integration suite; expected PASS for both drivers and all lifecycle cases.

- [ ] **Step 5: Commit**

```bash
git add compose.yaml .docker/Caddyfile phpunit.xml native/test tests/Feature/NativeProxyControlContractTest.php
git commit -m "test: cover native proxy end to end"
```

## Task 26: Build non-root production proxy image and production Compose topology

**Files:**

- Create: `Dockerfile.native`
- Modify: `.docker/Caddyfile`
- Modify: `.docker/production-supervisord.conf`
- Modify: `Dockerfile.production`
- Modify: `compose.production.yaml`
- Modify: `.env.production.example`
- Modify: `docs/operations/production.md`
- Test: Docker smoke commands documented below

- [ ] **Step 1: Write/execute the failing production topology check**

Run before changes:

```bash
docker compose -f compose.production.yaml config | rg native-proxy
```

Expected: FAIL/no match.

- [ ] **Step 2: Implement multi-stage non-root image**

Build `crucible-proxy` with Go 1.26.1, `CGO_ENABLED=0`, pinned modules, stripped binary, OCI source/version/revision/license labels, read-only root filesystem compatibility, non-root user, and no CLI binary in the runtime image.

- [ ] **Step 3: Add production service**

Use the same immutable release tag and internal control/Redis network with no host mapping for 8081, 5432, or 3306. Copy `.docker/Caddyfile` into the production app image and start Octane/FrankenPHP with `--caddyfile=/etc/caddy/Caddyfile`; route only `/.well-known/crucible-native-client.json` and `/native-tunnel/*` to `native-proxy:8081`, and keep publishing the existing app port as the single origin. Configure WebSocket upgrades, disabled buffering/compression, bounded headers, long-lived tunnel timeouts, health/readiness, resource limits, secrets from `.env.production`, and graceful stop longer than the drain timeout. Derive all public URLs from `APP_URL`; do not introduce `NATIVE_PROXY_PUBLIC_URL`.

- [ ] **Step 4: Build and smoke test**

Run:

```bash
docker compose -f compose.production.yaml config
docker build -f Dockerfile.native -t hephaestus/crucible-db-native:test .
docker run --rm --read-only --tmpfs /tmp hephaestus/crucible-db-native:test version
```

Expected: valid Compose, successful build, non-root execution, version output, no embedded secrets.

- [ ] **Step 5: Commit**

```bash
git add Dockerfile.native Dockerfile.production .docker/Caddyfile .docker/production-supervisord.conf compose.production.yaml .env.production.example docs/operations/production.md
git commit -m "build: package native proxy service"
```

## Task 27: Add Go CI, security, fuzz, compatibility, and image gates

**Files:**

- Create: `.github/workflows/native.yml`
- Create: `native/.golangci.yml` or `staticcheck.conf` only if required by selected tool
- Modify: `.github/dependabot.yml` if present, otherwise create it with composer/npm/gomod/docker ecosystems
- Modify: `README.md`

- [ ] **Step 1: Add a deliberately failing workflow-local check**

Use `actionlint` locally or inspect workflow with `gh workflow view` after push; initially point one command at a nonexistent package and confirm CI failure, then correct it before commit. Do not merge an unobserved workflow.

- [ ] **Step 2: Implement required jobs**

Jobs run Go format diff, `go mod verify`, `go vet`, staticcheck, `govulncheck`, unit tests, `go test -race ./...`, bounded fuzz smoke for both protocols/tunnel, PHP/Go contract fixtures, PostgreSQL 14/15/16/17/18 and MySQL 8.0/8.4 integration matrices, Docker build, Trivy/Grype scan, SBOM, and non-root/read-only smoke.

- [ ] **Step 3: Verify locally where possible**

Run: `cd native && test -z "$(gofmt -l .)" && go mod verify && go vet ./... && go test -race ./...`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add .github native README.md
git commit -m "ci: verify native proxy and cli"
```

## Task 28: Add signed multi-platform CLI release pipeline

**Files:**

- Create: `.goreleaser.yaml`
- Create: `.github/workflows/release-native.yml`
- Create: `scripts/verify-native-release.sh`
- Modify: `docs/operations/production.md`
- Modify: `SECURITY.md`

- [ ] **Step 1: Write release configuration assertions**

The verification script must fail unless artifacts include macOS/Linux/Windows amd64+arm64, SHA-256 checksums, signatures, SBOM/provenance, `.deb`, `.rpm`, and Homebrew formula metadata and unless archives contain only `crucible`, license, and README.

- [ ] **Step 2: Run against no artifacts and verify RED**

Run: `scripts/verify-native-release.sh dist`
Expected: FAIL because artifacts do not exist.

- [ ] **Step 3: Implement GoReleaser/tag workflow**

Use tag-derived versions, reproducible flags, GitHub OIDC keyless signing when available, immutable release assets, checksums, and no automatic CLI self-update. Release workflow runs only after test/native workflows pass for the tag commit.

- [ ] **Step 4: Build snapshot and verify GREEN**

Run: `goreleaser release --snapshot --clean`
Run: `scripts/verify-native-release.sh dist`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add .goreleaser.yaml .github/workflows/release-native.yml scripts docs/operations/production.md SECURITY.md
git commit -m "build: release signed crucible cli artifacts"
```

## Task 29: Complete compatibility, load, fuzz, and privacy release suites

**Files:**

- Create: `native/test/compatibility/*`
- Create: `native/test/load/*`
- Create: `docs/reference/native-client-compatibility.md`
- Create: `docs/security/native-client-threat-model.md`
- Modify: `docs/capture/README.md`

- [ ] **Step 1: Add automated compatibility scripts**

Exercise `psql`, MySQL CLI, PostgreSQL JDBC, and MySQL JDBC with startup metadata, schema discovery, reads, writes, transactions, savepoints, prepared statements, cancellation, error recovery, expiry, and reconnect. Assert audit counts/fingerprints and absence of parameter markers in logs/storage.

- [ ] **Step 2: Add bounded load and fault tests**

Test connection ceilings, concurrent leases, 10 MiB rejection, 32 MiB backpressure, idle timeout, long-running cancellation, Redis/Laravel/target outage, proxy restart/drain, memory/goroutine stability, and <=5-second revocation propagation.

- [ ] **Step 3: Add client release matrix**

Document tested versions/results for DBeaver, DataGrip, TablePlus, MySQL Workbench, CLI/JDBC clients. A client is marked supported only after manual or automated evidence; unsupported features list the stable error shown.

- [ ] **Step 4: Run release suites**

Run: `cd native && go test -race ./...`
Run: `cd native && go test -tags=integration ./test/integration ./test/compatibility ./test/load -v`
Run bounded fuzz targets for tunnel, PostgreSQL, and MySQL.
Expected: PASS, no races, no crashes, bounded resources, privacy assertions green.

- [ ] **Step 5: Commit**

```bash
git add native/test docs/reference/native-client-compatibility.md docs/security/native-client-threat-model.md docs/capture/README.md
git commit -m "test: qualify native client access"
```

## Task 30: Publish complete end-user, admin, operator, CLI, and architecture documentation

**Files:**

- Create: `docs/guides/native-client-access.md`
- Create: `docs/admin-guide/native-client-access.md`
- Create: `docs/operations/native-proxy.md`
- Create: `docs/reference/crucible-cli.md`
- Create: `docs/architecture/native-proxy.md`
- Modify: `docs/index.md`
- Modify: `docs/product/overview.md`
- Modify: `docs/admin-guide/navigation.md`
- Modify: `docs/operations/production.md`
- Modify: `docs/reference/configuration.md`
- Modify: `docs/reference/statuses.md`
- Modify: `docs/reference/supported-sql.md`
- Modify: `docs/architecture/decisions.md`
- Modify: `mkdocs.yml`
- Modify: `README.md`
- Test: `tests/Feature/DocumentationSeederTest.php`

- [ ] **Step 1: Write failing documentation fixture/navigation assertions**

Extend documentation seeding/screenshots to create an approved Native Client session, active lease, connection, and statements with sanitized data. Assert every new page is in navigation and internal links resolve.

- [ ] **Step 2: Verify RED**

Run: `php artisan test --compact tests/Feature/DocumentationSeederTest.php`
Expected: FAIL for missing native fixtures/pages.

- [ ] **Step 3: Write role-specific documentation**

Include the complete request/review/activation/CLI/DBeaver flow, one-time credentials, rotation/revoke, read-only/write consequences, CLI install/signature verification, all flags/exit codes/JSON events, single-origin `APP_URL` routing, fixed discovery and `/native-tunnel/*` paths, optional outer-auth path exceptions, explicit loopback-only and local database-TLS-disabled client profiles, upstream TLS distinction, health/metrics, backup/recovery, drain/upgrade, incident response, blocked protocol capabilities, trust boundaries, and troubleshooting. State that no second subdomain, DNS record, public proxy port, or TLS certificate is required.

- [ ] **Step 4: Capture consistent screenshots**

Use sanitized documentation fixtures at 1440x900 for three-choice creation, reviewer detail, one-time credentials, CLI instructions, active connections/statements, admin policy, and operator health. Never capture a real password/token or production hostname.

- [ ] **Step 5: Verify documentation**

Run: `php artisan test --compact tests/Feature/DocumentationSeederTest.php`
Run: `.venv-docs/bin/mkdocs build --strict`
Run the existing Playwright desktop/mobile documentation crawl.
Expected: PASS, no broken links, overflow, console errors, or stale feature copy.

- [ ] **Step 6: Commit**

```bash
git add docs mkdocs.yml README.md tests/Feature/DocumentationSeederTest.php database/seeders
git commit -m "docs: document native client access"
```

## Task 31: Run the complete release-worthiness gate

**Files:**

- Modify only defects exposed by verification, each with a failing regression test first.

- [ ] **Step 1: Run Laravel/frontend checks**

```bash
vendor/bin/pint --dirty --format agent
composer ci:check
npm audit --audit-level=high
composer audit --locked --no-interaction
```

Expected: all pass, zero high-severity advisories.

- [ ] **Step 2: Run Go checks**

```bash
cd native
test -z "$(gofmt -l .)"
go mod verify
go vet ./...
staticcheck ./...
govulncheck ./...
go test -race ./...
```

Expected: all pass.

- [ ] **Step 3: Run full integration/compatibility/load/fuzz suites**

Run every command from Tasks 25 and 29 against clean containers. Expected: both engines pass, privacy assertions pass, revocation <=5 seconds, no races/leaks/crashes.

- [ ] **Step 4: Verify production images and Compose**

Build app and native images from the exact commit, start disposable production topology, run migrations, verify app/proxy/Redis health, device flow, PostgreSQL/MySQL read/write sessions, process status, graceful drain, OCI metadata, non-root/read-only filesystem, SBOM, and vulnerability scan.

- [ ] **Step 5: Verify docs and release artifacts**

Run strict MkDocs/browser crawl, snapshot GoReleaser, checksum/signature verification, and inspect every archive/package on its target OS where CI provides runners.

- [ ] **Step 6: Final diff and commit**

```bash
git diff --check
git status --short
git add <only verified regression fixes>
git commit -m "fix: close native client release gaps"
```

Skip the final commit when no verification defects required changes. Do not tag, push images, or create a release until the user explicitly requests the release workflow.
