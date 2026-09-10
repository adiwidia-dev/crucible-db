<?php

namespace Tests\Feature;

use App\Enums\DatabaseDriver;
use App\Enums\NativeProxyConnectionStatus;
use App\Enums\NativeProxyDeviceAuthorizationStatus;
use App\Enums\NativeProxyLeaseStatus;
use App\Http\Middleware\VerifyNativeProxyControlRequest;
use App\Models\NativeProxyConnection;
use App\Models\NativeProxyDeviceAuthorization;
use App\Models\NativeProxyLease;
use Database\Seeders\NativeProxyIntegrationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class NativeProxyControlContractTest extends TestCase
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

    public function test_same_origin_routes_are_private_and_fixed_in_the_compose_topology(): void
    {
        $caddyfile = file_get_contents(base_path('.docker/Caddyfile'));
        $developmentDockerfile = file_get_contents(base_path('Dockerfile'));
        $developmentCompose = file_get_contents(base_path('compose.yaml'));
        $productionCompose = file_get_contents(base_path('compose.production.yaml'));

        $this->assertNotFalse($caddyfile);
        $this->assertNotFalse($developmentDockerfile);
        $this->assertNotFalse($developmentCompose);
        $this->assertNotFalse($productionCompose);
        $this->assertStringContainsString('/.well-known/crucible-native-client.json', $caddyfile);
        $this->assertStringContainsString('/native-tunnel/*', $caddyfile);
        $this->assertStringContainsString('reverse_proxy native-proxy:8081', $caddyfile);
        $this->assertStringContainsString('@internal_control path /internal /internal/*', $caddyfile);
        $this->assertMatchesRegularExpression('/handle @internal_control \{\s*respond 404\s*\}/', $caddyfile);
        $this->assertSame(2, substr_count($caddyfile, '@internal_control path /internal /internal/*'));
        $this->assertStringContainsString('http://:8001', $caddyfile);
        $this->assertStringNotContainsString('reverse_proxy app:8000', $caddyfile);
        $this->assertStringContainsString('NATIVE_PROXY_CONTROL_URL: http://app:8001', $developmentCompose);
        $this->assertStringContainsString('NATIVE_PROXY_CONTROL_URL: http://app:8001', $productionCompose);
        $this->assertStringContainsString('--caddyfile=/etc/caddy/Caddyfile', $developmentDockerfile);
        $this->assertStringNotContainsString("\n  gateway:\n", $developmentCompose);
        $this->assertStringNotContainsString("\n  gateway:\n", $productionCompose);
        $this->assertStringContainsString('${CRUCIBLE_BIND_ADDRESS:-127.0.0.1}:${CRUCIBLE_HTTP_PORT:-8000}:8000', $developmentCompose);
        $this->assertStringContainsString('${CRUCIBLE_BIND_ADDRESS:-127.0.0.1}:${CRUCIBLE_HTTP_PORT:-8000}:8000', $productionCompose);
        $this->assertStringNotContainsString('"5432:5432"', $developmentCompose);
        $this->assertStringNotContainsString('"3306:3306"', $developmentCompose);
        $this->assertStringNotContainsString('"5432:5432"', $productionCompose);
        $this->assertStringNotContainsString('"3306:3306"', $productionCompose);
        $this->assertMatchesRegularExpression('/  native-proxy:\n(?:(?!\nvolumes:).)*NATIVE_PROXY_CONTROL_SECRET/s', $productionCompose);
        $this->assertStringNotContainsString('change-me-before-production', file_get_contents(base_path('compose.yaml')));
        preg_match('/  native-proxy:\n(?<service>.*?)(?=\nvolumes:)/s', $productionCompose, $nativeProxyService);
        $this->assertStringNotContainsString('env_file:', $nativeProxyService['service'] ?? '');
        $this->assertStringNotContainsString('APP_KEY:', $nativeProxyService['service'] ?? '');
        $this->assertStringNotContainsString('MAIL_PASSWORD:', $nativeProxyService['service'] ?? '');
    }

    public function test_native_protocol_names_are_explicitly_mapped_at_the_control_boundary(): void
    {
        $this->assertSame('postgresql', DatabaseDriver::PostgreSql->nativeProxyProtocol());
        $this->assertSame(DatabaseDriver::PostgreSql, DatabaseDriver::fromNativeProxyProtocol('postgresql'));
        $this->assertSame('mysql', DatabaseDriver::MySql->nativeProxyProtocol());
        $this->assertSame(DatabaseDriver::MySql, DatabaseDriver::fromNativeProxyProtocol('mysql'));
    }

    public function test_integration_seeder_creates_approved_devices_for_both_real_proxy_protocols(): void
    {
        $this->seed(NativeProxyIntegrationSeeder::class);

        NativeProxyConnection::factory()->create([
            'lease_id' => NativeProxyIntegrationSeeder::PostgreSqlLeaseId,
        ]);
        $this->seed(NativeProxyIntegrationSeeder::class);

        $this->assertSame(2, NativeProxyLease::query()->count());
        $this->assertSame(0, NativeProxyConnection::query()->count());
        $this->assertSame(2, NativeProxyDeviceAuthorization::query()
            ->where('status', NativeProxyDeviceAuthorizationStatus::Approved)
            ->count());
        $this->assertSame(
            DatabaseDriver::PostgreSql,
            NativeProxyLease::query()->findOrFail(NativeProxyIntegrationSeeder::PostgreSqlLeaseId)->protocol,
        );
        $this->assertSame(
            DatabaseDriver::MySql,
            NativeProxyLease::query()->findOrFail(NativeProxyIntegrationSeeder::MySqlLeaseId)->protocol,
        );
    }

    public function test_postgresql_device_token_and_tunnel_contract_use_the_external_protocol_name(): void
    {
        config([
            'native_proxy.enabled' => true,
            'native_proxy.control_secret' => 'native-proxy-control-secret-for-contract-test',
        ]);
        $this->seed(NativeProxyIntegrationSeeder::class);

        $tokenResponse = $this->signedPost(
            '/internal/native-proxy/v1/device-token',
            ['device_code' => NativeProxyIntegrationSeeder::PostgreSqlDeviceCode],
            'contract-device-token-request',
        )->assertOk();
        $accessToken = $tokenResponse->json('access_token');
        $deviceAuthorizationId = $tokenResponse->json('device_authorization_id');

        $heartbeatPayload = [
            'lease_id' => NativeProxyIntegrationSeeder::PostgreSqlLeaseId,
            'device_authorization_id' => $deviceAuthorizationId,
            'bearer_hash' => hash('sha256', $accessToken),
        ];
        $this->signedPost(
            '/internal/native-proxy/v1/leases/heartbeat',
            $heartbeatPayload,
            'contract-active-lease-heartbeat',
        )->assertOk()->assertJson([
            'status' => 'continue',
            'continue' => true,
        ]);

        $this->signedPost('/internal/native-proxy/v1/tunnels/authorize', [
            'lease_id' => NativeProxyIntegrationSeeder::PostgreSqlLeaseId,
            'device_authorization_id' => $deviceAuthorizationId,
            'proxy_connection_id' => 'contract-proxy-connection-01',
            'proxy_instance_id' => 'proxy-contract',
            'protocol' => 'postgresql',
            'bearer_hash' => hash('sha256', $accessToken),
        ], 'contract-tunnel-request')->assertOk()->assertJsonPath('protocol', 'postgresql');

        NativeProxyLease::query()
            ->findOrFail(NativeProxyIntegrationSeeder::PostgreSqlLeaseId)
            ->forceFill([
                'status' => NativeProxyLeaseStatus::Revoked,
                'revoked_at' => now(),
            ])->save();

        $this->signedPost(
            '/internal/native-proxy/v1/leases/heartbeat',
            $heartbeatPayload,
            'contract-revoked-lease-heartbeat',
        )->assertOk()->assertJson([
            'status' => 'revoked',
            'continue' => false,
        ]);
    }

    public function test_signed_connection_lifecycle_routes_resolve_the_native_connection_model(): void
    {
        config([
            'native_proxy.enabled' => true,
            'native_proxy.control_secret' => 'native-proxy-control-secret-for-contract-test',
        ]);
        $connection = NativeProxyConnection::factory()->create([
            'proxy_instance_id' => 'proxy-contract',
            'status' => NativeProxyConnectionStatus::Reserved,
            'reservation_expires_at' => now()->addSeconds(15),
        ]);

        $this->signedPost(
            "/internal/native-proxy/v1/connections/{$connection->id}/authenticated",
            ['proxy_instance_id' => 'proxy-contract'],
            'contract-authenticated-request',
        )->assertOk()->assertJsonPath('status', 'continue');

        $this->assertSame(NativeProxyConnectionStatus::Active, $connection->fresh()->status);
    }

    public function test_signed_traffic_reports_resolve_the_client_facing_proxy_connection_id(): void
    {
        config([
            'native_proxy.enabled' => true,
            'native_proxy.control_secret' => 'native-proxy-control-secret-for-contract-test',
        ]);
        $connection = NativeProxyConnection::factory()->active()->create([
            'proxy_instance_id' => 'proxy-contract',
            'proxy_connection_id' => 'contract-client-connection',
        ]);

        $this->signedPost('/internal/native-proxy/v1/connections/traffic', [
            'proxy_instance_id' => 'proxy-contract',
            'proxy_connection_id' => 'contract-client-connection',
            'bytes_received' => 128,
            'bytes_sent' => 256,
        ], 'contract-traffic-request')->assertOk()->assertJsonPath('status', 'recorded');

        $connection->refresh();
        $this->assertSame(128, $connection->bytes_received);
        $this->assertSame(256, $connection->bytes_sent);
    }

    public function test_control_plane_readiness_requires_a_valid_proxy_signature(): void
    {
        config([
            'native_proxy.enabled' => true,
            'native_proxy.control_secret' => 'native-proxy-control-secret-for-contract-test',
        ]);

        $this->getJson('/internal/native-proxy/v1/health')->assertUnauthorized();

        $path = '/internal/native-proxy/v1/health';
        $timestamp = now()->timestamp;
        $requestId = 'contract-health-request';
        $body = 'null';
        $signature = hash_hmac('sha256', implode("\n", [
            'GET',
            $path,
            (string) $timestamp,
            $requestId,
            hash('sha256', $body),
        ]), 'native-proxy-control-secret-for-contract-test');

        $request = Request::create($path, 'GET', server: [
            'HTTP_X_CRUCIBLE_PROXY_ID' => 'proxy-contract',
            'HTTP_X_CRUCIBLE_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_CRUCIBLE_REQUEST_ID' => $requestId,
            'HTTP_X_CRUCIBLE_SIGNATURE' => $signature,
        ], content: $body);
        $response = app(VerifyNativeProxyControlRequest::class)->handle(
            $request,
            fn () => response()->json(['status' => 'ok']),
        );
        $this->assertSame(200, $response->getStatusCode());

        $this->withoutMiddleware(VerifyNativeProxyControlRequest::class)
            ->getJson($path)
            ->assertOk()
            ->assertJsonPath('status', 'ok');
    }

    /** @param array<string, mixed> $payload */
    private function signedPost(string $path, array $payload, string $requestId): TestResponse
    {
        $timestamp = now()->timestamp;
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', implode("\n", [
            'POST',
            $path,
            (string) $timestamp,
            $requestId,
            hash('sha256', $body),
        ]), 'native-proxy-control-secret-for-contract-test');

        return $this->withHeaders([
            'X-Crucible-Proxy-Id' => 'proxy-contract',
            'X-Crucible-Timestamp' => (string) $timestamp,
            'X-Crucible-Request-Id' => $requestId,
            'X-Crucible-Signature' => $signature,
        ])->postJson($path, $payload);
    }
}
