<?php

namespace Tests\Feature;

use App\Enums\AccessMode;
use App\Enums\AccessTransport;
use App\Enums\NativeProxyDeviceAuthorizationStatus;
use App\Enums\NativeProxyLeaseStatus;
use App\Enums\QueryRequestStatus;
use App\Models\AuditLog;
use App\Models\DatabaseConnection;
use App\Models\NativeProxyDeviceAuthorization;
use App\Models\NativeProxyLease;
use App\Models\NativeProxyToken;
use App\Models\QueryRequest;
use App\Models\QuerySession;
use App\Models\Role;
use App\Models\RoleDatabasePermission;
use App\Models\User;
use App\Services\NativeProxy\DeviceAuthorizationWorkflow;
use App\Services\NativeProxy\LeaseWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class NativeProxyDeviceAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'native_proxy.control_encrypted_responses_required' => false,
            'native_proxy.allowed_proxy_ids' => [],
            'native_proxy.allowed_proxy_ips' => [],
        ]);
    }

    public function test_device_authorization_uses_one_time_codes_and_issues_a_single_token_after_owner_approval(): void
    {
        [$owner, $lease] = $this->activeLease();
        $workflow = app(DeviceAuthorizationWorkflow::class);

        $challenge = $workflow->begin($lease, [
            'cli_version' => '0.1.0',
            'operating_system' => 'darwin',
            'architecture' => 'arm64',
            'device_label' => 'Developer MacBook',
        ]);

        $authorization = $challenge->authorization;
        $storedAuthorization = NativeProxyDeviceAuthorization::query()->findOrFail($authorization->id);

        $this->assertSame(32, strlen(base64_decode(strtr($challenge->deviceCode, '-_', '+/'), true)));
        $this->assertMatchesRegularExpression('/^[23456789ABCDEFGHJKLMNPQRSTUVWXYZ]{4}-[23456789ABCDEFGHJKLMNPQRSTUVWXYZ]{4}$/', $challenge->userCode);
        $this->assertSame(NativeProxyDeviceAuthorizationStatus::Pending, $authorization->status);
        $this->assertSame(5, $authorization->polling_interval_seconds);
        $this->assertTrue($authorization->expires_at->between(now()->addSeconds(299), now()->addSeconds(301)));
        $this->assertNotSame($challenge->deviceCode, $storedAuthorization->device_code_hash);
        $this->assertStringNotContainsString($challenge->deviceCode, $storedAuthorization->device_code_hash);

        $workflow->approve($authorization, $owner);
        $result = $workflow->poll($challenge->deviceCode);

        $this->assertTrue($result->isIssued());
        $this->assertSame(32, strlen(base64_decode(strtr((string) $result->token, '-_', '+/'), true)));
        $this->assertNotNull($result->expiresIn);
        $this->assertSame(NativeProxyDeviceAuthorizationStatus::Consumed, $authorization->refresh()->status);
        $this->assertTrue(NativeProxyToken::query()->where('device_authorization_id', $authorization->id)->exists());
        $this->assertSame('invalid_device_code', $workflow->poll($challenge->deviceCode)->status);
        $this->assertTrue(AuditLog::query()->where('action', 'native_proxy.device_authorization_approved')->exists());
        $this->assertTrue(AuditLog::query()->where('action', 'native_proxy.device_authorization_consumed')->exists());
    }

    public function test_device_polling_enforces_pending_slow_down_denial_expiry_and_active_token_limits(): void
    {
        [$owner, $lease] = $this->activeLease();
        $workflow = app(DeviceAuthorizationWorkflow::class);
        $challenge = $workflow->begin($lease, $this->metadata());

        $this->assertSame('authorization_pending', $workflow->poll($challenge->deviceCode)->status);
        $slowDown = $workflow->poll($challenge->deviceCode);
        $this->assertSame('slow_down', $slowDown->status);
        $this->assertSame(10, $slowDown->pollingIntervalSeconds);
        $this->assertSame(10, $challenge->authorization->refresh()->polling_interval_seconds);

        $workflow->deny($challenge->authorization, $owner);
        $this->assertSame('access_denied', $workflow->poll($challenge->deviceCode)->status);

        $expiredChallenge = $workflow->begin($lease, $this->metadata());
        $expiredChallenge->authorization->forceFill(['expires_at' => now()->subSecond()])->save();
        $this->assertSame('expired_token', $workflow->poll($expiredChallenge->deviceCode)->status);

        NativeProxyToken::factory()->count(3)->for($lease, 'lease')->create([
            'expires_at' => $lease->expires_at,
            'revoked_at' => null,
        ]);
        $limitedChallenge = $workflow->begin($lease, $this->metadata());
        $workflow->approve($limitedChallenge->authorization, $owner);
        $this->assertSame('access_denied', $workflow->poll($limitedChallenge->deviceCode)->status);
        $this->assertSame(NativeProxyDeviceAuthorizationStatus::Approved, $limitedChallenge->authorization->refresh()->status);
    }

    public function test_only_the_session_owner_or_administrator_can_approve_an_active_device_authorization(): void
    {
        [$owner, $lease] = $this->activeLease();
        $workflow = app(DeviceAuthorizationWorkflow::class);
        $challenge = $workflow->begin($lease, $this->metadata());
        $otherUser = User::factory()->create();

        $this->expectExceptionObject(ValidationException::withMessages([
            'device_authorization' => 'Only the session owner can approve this device.',
        ]));

        $workflow->approve($challenge->authorization, $otherUser);
    }

    public function test_owner_can_find_and_decide_on_a_device_authorization_in_the_browser(): void
    {
        [$owner, $lease] = $this->activeLease();
        $challenge = app(DeviceAuthorizationWorkflow::class)->begin($lease, $this->metadata());

        $this->actingAs($owner)
            ->get(route('native-proxy.device-authorizations.confirm'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('native-proxy/device-authorization')
                ->where('authorization', null));

        $this->actingAs($owner)
            ->get(route('native-proxy.device-authorizations.confirm', ['user_code' => strtolower($challenge->userCode)]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('native-proxy/device-authorization')
                ->where('authorization', null)
                ->missing('user_code'));

        $this->actingAs($owner)
            ->post(route('native-proxy.device-authorizations.resolve'), ['user_code' => strtolower($challenge->userCode)])
            ->assertRedirect(route('native-proxy.device-authorizations.show', $challenge->authorization));

        $this->assertSame(NativeProxyDeviceAuthorizationStatus::Approved, $challenge->authorization->refresh()->status);

        $this->actingAs($owner)
            ->get(route('native-proxy.device-authorizations.show', $challenge->authorization))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('native-proxy/device-authorization')
                ->where('authorization.code_status', NativeProxyDeviceAuthorizationStatus::Approved->value)
                ->missing('user_code'));
    }

    public function test_device_authorization_limits_pending_challenges_per_lease(): void
    {
        config()->set('native_proxy.max_pending_device_authorizations_per_lease', 1);
        [, $lease] = $this->activeLease();
        $workflow = app(DeviceAuthorizationWorkflow::class);

        $workflow->begin($lease, $this->metadata());

        $this->expectExceptionObject(ValidationException::withMessages([
            'lease' => 'Too many device authorizations are awaiting approval for this lease.',
        ]));

        $workflow->begin($lease, $this->metadata());
    }

    public function test_other_users_cannot_approve_another_users_device_authorization(): void
    {
        [, $lease] = $this->activeLease();
        $challenge = app(DeviceAuthorizationWorkflow::class)->begin($lease, $this->metadata());
        $otherUser = User::factory()->create();

        $this->actingAs($otherUser)
            ->get(route('native-proxy.device-authorizations.confirm', ['user_code' => $challenge->userCode]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('native-proxy/device-authorization')
                ->where('authorization', null));

        $this->actingAs($otherUser)
            ->post(route('native-proxy.device-authorizations.resolve'), ['user_code' => $challenge->userCode])
            ->assertSessionHasErrors('user_code');
    }

    public function test_control_device_authorization_and_token_routes_enforce_their_configured_rate_limits(): void
    {
        config([
            'native_proxy.enabled' => true,
            'native_proxy.control_secret' => 'device-rate-limit-test-secret',
            'native_proxy.device_authorization_rate_limit' => 1,
            'native_proxy.device_token_rate_limit' => 1,
        ]);
        [, $lease] = $this->activeLease();
        $metadata = $this->metadata();
        $metadata['lease_id'] = $lease->id;

        $this->signedControlPost('/internal/native-proxy/v1/device-authorizations', $metadata, 'device-rate-limit-one', 'proxy-device-rate')
            ->assertCreated();
        $this->signedControlPost('/internal/native-proxy/v1/device-authorizations', $metadata, 'device-rate-limit-two', 'proxy-device-rate')
            ->assertStatus(429);

        $challenge = app(DeviceAuthorizationWorkflow::class)->begin($lease, $this->metadata());

        $this->signedControlPost('/internal/native-proxy/v1/device-token', ['device_code' => $challenge->deviceCode], 'token-rate-limit-one', 'proxy-token-rate')
            ->assertBadRequest()
            ->assertJsonPath('error', 'authorization_pending');
        $this->signedControlPost('/internal/native-proxy/v1/device-token', ['device_code' => $challenge->deviceCode], 'token-rate-limit-two', 'proxy-token-rate')
            ->assertStatus(429)
            ->assertJsonPath('error', 'slow_down')
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_control_device_authorization_returns_public_app_url_for_browser_verification(): void
    {
        config([
            'app.url' => 'https://crucible.example.test',
            'native_proxy.enabled' => true,
            'native_proxy.control_secret' => 'device-rate-limit-test-secret',
        ]);
        [, $lease] = $this->activeLease();
        $metadata = $this->metadata();
        $metadata['lease_id'] = $lease->id;

        $response = $this
            ->withServerVariables(['HTTP_HOST' => 'app:8000'])
            ->signedControlPost('/internal/native-proxy/v1/device-authorizations', $metadata, 'device-public-url', 'proxy-device-url')
            ->assertCreated()
            ->assertJsonPath('verification_uri', 'https://crucible.example.test/native-proxy/device-authorizations/confirm')
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->assertSame(
            'https://crucible.example.test/native-proxy/device-authorizations/confirm?user_code='.$response->json('user_code'),
            $response->json('verification_uri_complete'),
        );
    }

    /**
     * @return array{0: User, 1: NativeProxyLease}
     */
    private function activeLease(): array
    {
        $connection = DatabaseConnection::factory()->postgresql()->create();
        $role = Role::factory()->developer()->create();
        $owner = User::factory()->withRole($role)->create();
        RoleDatabasePermission::factory()->create([
            'role_id' => $role->id,
            'database_connection_id' => $connection->id,
            'access_mode' => AccessMode::Read,
            'native_proxy_access_mode' => AccessMode::Read,
        ]);
        $request = QueryRequest::factory()->queryAccess()->approved()->create([
            'requester_id' => $owner->id,
            'database_connection_id' => $connection->id,
            'access_transport' => AccessTransport::NativeProxy,
            'requested_access_mode' => AccessMode::Read,
            'status' => QueryRequestStatus::Running,
        ]);
        $session = QuerySession::factory()->create([
            'query_request_id' => $request->id,
            'user_id' => $owner->id,
            'database_connection_id' => $connection->id,
        ]);
        $lease = app(LeaseWorkflow::class)->create($session, $owner, bin2hex(random_bytes(16)));

        return [$owner, $lease->lease];
    }

    /**
     * @return array{cli_version:string, operating_system:string, architecture:string, device_label:string}
     */
    private function metadata(): array
    {
        return [
            'cli_version' => '0.1.0',
            'operating_system' => 'linux',
            'architecture' => 'amd64',
            'device_label' => 'CI runner',
        ];
    }

    /**
     * @param  array<string, string>  $payload
     */
    private function signedControlPost(string $path, array $payload, string $requestId, string $proxyId): TestResponse
    {
        $timestamp = now()->timestamp;
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', implode("\n", [
            'POST',
            $path,
            (string) $timestamp,
            $requestId,
            hash('sha256', $body),
        ]), 'device-rate-limit-test-secret');

        return $this->withHeaders([
            'X-Crucible-Proxy-Id' => $proxyId,
            'X-Crucible-Timestamp' => (string) $timestamp,
            'X-Crucible-Request-Id' => $requestId,
            'X-Crucible-Signature' => $signature,
        ])->postJson($path, $payload);
    }

    public function test_disabled_users_cannot_approve_devices(): void
    {
        [$owner, $lease] = $this->activeLease();
        $workflow = app(DeviceAuthorizationWorkflow::class);
        $challenge = $workflow->begin($lease, $this->metadata());
        $owner->forceFill(['disabled_at' => now()])->save();

        $this->expectException(ValidationException::class);

        $workflow->approve($challenge->authorization, $owner);
    }

    public function test_expired_leases_cannot_approve_devices(): void
    {
        [$owner, $lease] = $this->activeLease();
        $workflow = app(DeviceAuthorizationWorkflow::class);
        $challenge = $workflow->begin($lease, $this->metadata());

        $lease->forceFill(['status' => NativeProxyLeaseStatus::Expired])->save();

        $this->expectException(ValidationException::class);

        $workflow->approve($challenge->authorization, $owner);
    }
}
