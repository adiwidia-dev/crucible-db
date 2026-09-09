<?php

namespace App\Services\NativeProxy;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JsonException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class NativeProxyControlResponseCipher
{
    public const ProtocolHeader = 'X-Crucible-Control-Protocol';

    public const ProtocolVersion = '2';

    public function encrypt(
        Response $response,
        string $secret,
        string $proxyInstanceId,
        string $timestamp,
        string $requestId,
    ): JsonResponse {
        $nonce = random_bytes(12);
        $tag = '';
        $status = $response->getStatusCode();
        $ciphertext = openssl_encrypt(
            (string) $response->getContent(),
            'aes-256-gcm',
            $this->key($secret),
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $this->additionalAuthenticatedData($proxyInstanceId, $timestamp, $requestId, $status),
            16,
        );

        if (! is_string($ciphertext) || strlen($tag) !== 16) {
            throw new RuntimeException('Unable to protect the native proxy control response.');
        }

        $encryptedResponse = response()->json([
            'version' => 2,
            'nonce' => base64_encode($nonce),
            'ciphertext' => base64_encode($ciphertext),
            'tag' => base64_encode($tag),
        ], $status);

        foreach (['Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining'] as $header) {
            if ($response->headers->has($header)) {
                $encryptedResponse->headers->set($header, (string) $response->headers->get($header));
            }
        }

        return $encryptedResponse
            ->header(self::ProtocolHeader, self::ProtocolVersion)
            ->header('Cache-Control', 'no-store, private');
    }

    /**
     * @return array<string, mixed>
     */
    public function decryptRequest(
        Request $request,
        string $secret,
        string $proxyInstanceId,
        string $timestamp,
        string $requestId,
    ): array {
        try {
            $envelope = json_decode($request->getContent(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Invalid encrypted native proxy control request.');
        }

        if (! is_array($envelope) || ($envelope['version'] ?? null) !== 2) {
            throw new RuntimeException('Invalid encrypted native proxy control request.');
        }

        $nonce = isset($envelope['nonce']) && is_string($envelope['nonce'])
            ? base64_decode($envelope['nonce'], true)
            : false;
        $ciphertext = isset($envelope['ciphertext']) && is_string($envelope['ciphertext'])
            ? base64_decode($envelope['ciphertext'], true)
            : false;
        $tag = isset($envelope['tag']) && is_string($envelope['tag'])
            ? base64_decode($envelope['tag'], true)
            : false;

        if (! is_string($nonce) || strlen($nonce) !== 12 || ! is_string($ciphertext) || ! is_string($tag) || strlen($tag) !== 16) {
            throw new RuntimeException('Invalid encrypted native proxy control request.');
        }

        $plaintext = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            $this->requestKey($secret),
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $this->requestAdditionalAuthenticatedData($request, $proxyInstanceId, $timestamp, $requestId),
        );

        if (! is_string($plaintext)) {
            throw new RuntimeException('Native proxy control request authentication failed.');
        }

        try {
            $payload = json_decode($plaintext, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Invalid native proxy control request payload.');
        }

        if (! is_array($payload)) {
            throw new RuntimeException('Invalid native proxy control request payload.');
        }

        return $payload;
    }

    private function key(string $secret): string
    {
        return hash_hmac('sha256', 'crucible-native-proxy-control-response-v2', $secret, true);
    }

    private function requestKey(string $secret): string
    {
        return hash_hmac('sha256', 'crucible-native-proxy-control-request-v2', $secret, true);
    }

    private function additionalAuthenticatedData(string $proxyInstanceId, string $timestamp, string $requestId, int $status): string
    {
        return implode("\n", [
            self::ProtocolVersion,
            $proxyInstanceId,
            $timestamp,
            $requestId,
            (string) $status,
        ]);
    }

    private function requestAdditionalAuthenticatedData(
        Request $request,
        string $proxyInstanceId,
        string $timestamp,
        string $requestId,
    ): string {
        return implode("\n", [
            self::ProtocolVersion,
            $proxyInstanceId,
            $request->method(),
            '/'.ltrim($request->path(), '/'),
            $timestamp,
            $requestId,
        ]);
    }
}
