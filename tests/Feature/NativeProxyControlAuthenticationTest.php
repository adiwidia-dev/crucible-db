<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class NativeProxyControlAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const string ControlSecret = 'native-proxy-control-secret-for-tests';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'native_proxy.enabled' => true,
            'native_proxy.control_secret' => self::ControlSecret,
        ]);
    }

    public function test_control_routes_require_a_correct_canonical_hmac_signature(): void
    {
        $this->signedPost('/internal/native-proxy/v1/tunnels/authorize', ['connection_id' => 'test-connection'])
            ->assertUnprocessable();

        $this->withHeaders([
            'X-Crucible-Proxy-Id' => 'proxy-01',
            'X-Crucible-Timestamp' => (string) now()->timestamp,
            'X-Crucible-Request-Id' => 'valid-request-id-01',
            'X-Crucible-Signature' => str_repeat('0', 64),
        ])->postJson('/internal/native-proxy/v1/tunnels/authorize', ['connection_id' => 'test-connection'])
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthorized.']);
    }

    public function test_control_signature_rejects_stale_reused_with_a_different_payload_and_tampered_requests(): void
    {
        $payload = ['connection_id' => 'test-connection'];
        $requestId = 'valid-request-id-02';

        $this->signedPost('/internal/native-proxy/v1/tunnels/authorize', $payload, $requestId)
            ->assertUnprocessable();
        $this->signedPost('/internal/native-proxy/v1/tunnels/authorize', $payload, $requestId)
            ->assertUnprocessable();
        $this->signedPost('/internal/native-proxy/v1/tunnels/authorize', ['connection_id' => 'different-connection'], $requestId)
            ->assertUnauthorized();
        $this->signedPost('/internal/native-proxy/v1/tunnels/authorize', $payload, 'valid-request-id-03', now()->subMinute()->timestamp)
            ->assertUnauthorized();

        $timestamp = now()->timestamp;
        $tamperedSignature = $this->signature('POST', '/internal/native-proxy/v1/device-token', $timestamp, 'valid-request-id-04', $payload);
        $this->withHeaders($this->headers($timestamp, 'valid-request-id-04', $tamperedSignature))
            ->postJson('/internal/native-proxy/v1/tunnels/authorize', $payload)
            ->assertUnauthorized();
    }

    public function test_control_api_fails_closed_when_the_feature_or_secret_is_not_configured(): void
    {
        config(['native_proxy.enabled' => false]);

        $this->signedPost('/internal/native-proxy/v1/tunnels/authorize', ['connection_id' => 'test-connection'])
            ->assertUnauthorized();

        config(['native_proxy.enabled' => true, 'native_proxy.control_secret' => null]);

        $this->signedPost('/internal/native-proxy/v1/tunnels/authorize', ['connection_id' => 'test-connection'], 'valid-request-id-05')
            ->assertUnauthorized();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function signedPost(string $path, array $payload, string $requestId = 'valid-request-id-01', ?int $timestamp = null): TestResponse
    {
        $timestamp ??= now()->timestamp;

        return $this->withHeaders($this->headers(
            $timestamp,
            $requestId,
            $this->signature('POST', $path, $timestamp, $requestId, $payload),
        ))->postJson($path, $payload);
    }

    /**
     * @return array<string, string>
     */
    private function headers(int $timestamp, string $requestId, string $signature): array
    {
        return [
            'X-Crucible-Proxy-Id' => 'proxy-01',
            'X-Crucible-Timestamp' => (string) $timestamp,
            'X-Crucible-Request-Id' => $requestId,
            'X-Crucible-Signature' => $signature,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function signature(string $method, string $path, int $timestamp, string $requestId, array $payload): string
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        return hash_hmac('sha256', implode("\n", [
            $method,
            $path,
            (string) $timestamp,
            $requestId,
            hash('sha256', $body),
        ]), self::ControlSecret);
    }
}
