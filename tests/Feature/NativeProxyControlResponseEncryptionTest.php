<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyNativeProxyControlRequest;
use App\Services\NativeProxy\NativeProxyControlResponseCipher;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class NativeProxyControlResponseEncryptionTest extends TestCase
{
    private const string ControlSecret = 'native-proxy-control-secret-for-tests';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'native_proxy.enabled' => true,
            'native_proxy.control_secret' => self::ControlSecret,
            'native_proxy.control_encrypted_responses_required' => true,
            'native_proxy.allowed_proxy_ids' => ['proxy-encryption-test'],
            'native_proxy.allowed_proxy_ips' => [],
        ]);
    }

    public function test_signed_control_responses_are_authenticated_and_encrypted(): void
    {
        $timestamp = now()->timestamp;
        $requestId = 'encrypted-response-request-01';
        $response = $this->signedGet('/internal/native-proxy/v1/health', $timestamp, $requestId);

        $response
            ->assertOk()
            ->assertHeader(NativeProxyControlResponseCipher::ProtocolHeader, NativeProxyControlResponseCipher::ProtocolVersion)
            ->assertJsonStructure(['version', 'nonce', 'ciphertext', 'tag']);

        $this->assertStringNotContainsString('status', $response->getContent());
        $this->assertSame(
            ['status' => 'ok'],
            $this->decrypt($response, $timestamp, $requestId),
        );
    }

    public function test_control_requests_without_the_encrypted_response_protocol_fail_closed(): void
    {
        $timestamp = now()->timestamp;
        $requestId = 'unencrypted-response-request-01';
        $signature = $this->signature('GET', '/internal/native-proxy/v1/health', $timestamp, $requestId, '');

        $this->withHeaders([
            'X-Crucible-Proxy-Id' => 'proxy-encryption-test',
            'X-Crucible-Timestamp' => (string) $timestamp,
            'X-Crucible-Request-Id' => $requestId,
            'X-Crucible-Signature' => $signature,
        ])->getJson('/internal/native-proxy/v1/health')
            ->assertUnauthorized();
    }

    public function test_mutating_control_requests_must_use_an_authenticated_encrypted_body(): void
    {
        $timestamp = now()->timestamp;
        $requestId = 'encrypted-request-body-01';
        $path = '/internal/native-proxy/v1/device-authorizations';
        $encryptedBody = $this->encryptRequest(
            ['lease_id' => 'non-existent-lease'],
            'POST',
            $path,
            $timestamp,
            $requestId,
        );

        $response = $this->signedRequest('POST', $path, $timestamp, $requestId, $encryptedBody, function (Request $request) {
            $this->assertSame('non-existent-lease', $request->string('lease_id')->toString());

            return response()->json(['message' => 'Lease not found.'], 404);
        });

        $response
            ->assertStatus(404)
            ->assertHeader(NativeProxyControlResponseCipher::ProtocolHeader, NativeProxyControlResponseCipher::ProtocolVersion);

        $this->assertStringNotContainsString('non-existent-lease', $encryptedBody);

        $plaintextRequestId = 'plaintext-request-body-01';
        $plaintextBody = json_encode(['lease_id' => 'non-existent-lease'], flags: JSON_THROW_ON_ERROR);

        $this->signedRequest('POST', $path, $timestamp, $plaintextRequestId, $plaintextBody, fn () => response()->json())
            ->assertUnauthorized();
    }

    public function test_control_requests_from_an_unlisted_source_ip_fail_closed(): void
    {
        config(['native_proxy.allowed_proxy_ips' => ['127.0.0.1']]);

        $timestamp = now()->timestamp;
        $requestId = 'unlisted-source-request-01';

        $this->signedRequest(
            'GET',
            '/internal/native-proxy/v1/health',
            $timestamp,
            $requestId,
            '',
            fn () => response()->json(['status' => 'ok']),
            '203.0.113.12',
        )
            ->assertUnauthorized();
    }

    public function test_forwarded_headers_cannot_spoof_the_native_proxy_socket_peer(): void
    {
        config(['native_proxy.allowed_proxy_ips' => ['127.0.0.1']]);

        $this->signedRequest(
            'GET',
            '/internal/native-proxy/v1/health',
            now()->timestamp,
            'forwarded-source-request-01',
            '',
            fn () => response()->json(['status' => 'ok']),
            '203.0.113.12',
            '127.0.0.1',
        )->assertUnauthorized();
    }

    private function signedGet(string $path, int $timestamp, string $requestId): TestResponse
    {
        return $this->signedRequest(
            'GET',
            $path,
            $timestamp,
            $requestId,
            '',
            fn () => response()->json(['status' => 'ok']),
        );
    }

    private function signedRequest(
        string $method,
        string $path,
        int $timestamp,
        string $requestId,
        string $body,
        Closure $next,
        string $remoteAddress = '127.0.0.1',
        ?string $forwardedFor = null,
    ): TestResponse {
        $server = [
            'REMOTE_ADDR' => $remoteAddress,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CRUCIBLE_PROXY_ID' => 'proxy-encryption-test',
            'HTTP_X_CRUCIBLE_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_CRUCIBLE_REQUEST_ID' => $requestId,
            'HTTP_X_CRUCIBLE_SIGNATURE' => $this->signature($method, $path, $timestamp, $requestId, $body),
            'HTTP_X_CRUCIBLE_CONTROL_PROTOCOL' => NativeProxyControlResponseCipher::ProtocolVersion,
        ];

        if ($forwardedFor !== null) {
            $server['HTTP_X_FORWARDED_FOR'] = $forwardedFor;
        }

        $request = Request::create($path, $method, server: $server, content: $body);
        $response = app(VerifyNativeProxyControlRequest::class)->handle($request, $next);

        return TestResponse::fromBaseResponse($response, $request);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function encryptRequest(array $payload, string $method, string $path, int $timestamp, string $requestId): string
    {
        $nonce = random_bytes(12);
        $tag = '';
        $key = hash_hmac('sha256', 'crucible-native-proxy-control-request-v2', self::ControlSecret, true);
        $aad = implode("\n", ['2', 'proxy-encryption-test', $method, $path, (string) $timestamp, $requestId]);
        $plaintext = json_encode($payload, flags: JSON_THROW_ON_ERROR);
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $aad, 16);

        $this->assertIsString($ciphertext);

        return json_encode([
            'version' => 2,
            'nonce' => base64_encode($nonce),
            'ciphertext' => base64_encode($ciphertext),
            'tag' => base64_encode($tag),
        ], flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private function decrypt(TestResponse $response, int $timestamp, string $requestId): array
    {
        $envelope = $response->json();
        $nonce = base64_decode($envelope['nonce'], true);
        $ciphertext = base64_decode($envelope['ciphertext'], true);
        $tag = base64_decode($envelope['tag'], true);
        $key = hash_hmac('sha256', 'crucible-native-proxy-control-response-v2', self::ControlSecret, true);
        $aad = implode("\n", ['2', 'proxy-encryption-test', (string) $timestamp, $requestId, (string) $response->getStatusCode()]);
        $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $aad);

        $this->assertIsString($plaintext);

        return json_decode($plaintext, true, flags: JSON_THROW_ON_ERROR);
    }

    private function signature(string $method, string $path, int $timestamp, string $requestId, string $body): string
    {
        return hash_hmac('sha256', implode("\n", [
            $method,
            $path,
            (string) $timestamp,
            $requestId,
            hash('sha256', $body),
        ]), self::ControlSecret);
    }
}
