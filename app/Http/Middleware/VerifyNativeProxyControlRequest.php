<?php

namespace App\Http\Middleware;

use App\Services\NativeProxy\NativeProxyControlResponseCipher;
use Closure;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class VerifyNativeProxyControlRequest
{
    public function __construct(private readonly NativeProxyControlResponseCipher $responseCipher) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('native_proxy.control_secret');
        $proxyInstanceId = $request->header('X-Crucible-Proxy-Id');
        $timestamp = $request->header('X-Crucible-Timestamp');
        $requestId = $request->header('X-Crucible-Request-Id');
        $signature = $request->header('X-Crucible-Signature');
        $protocolVersion = $request->header(NativeProxyControlResponseCipher::ProtocolHeader);
        $requiresEncryptedResponses = (bool) config('native_proxy.control_encrypted_responses_required');

        if (! config('native_proxy.enabled') || ! is_string($secret) || $secret === '' || ! is_string($proxyInstanceId) || ! preg_match('/^[A-Za-z0-9._-]{1,128}$/', $proxyInstanceId) || ! is_string($timestamp) || ! ctype_digit($timestamp) || ! is_string($requestId) || ! preg_match('/^[A-Za-z0-9_-]{16,128}$/', $requestId) || ! is_string($signature) || ! preg_match('/^[a-f0-9]{64}$/i', $signature) || ($requiresEncryptedResponses && $protocolVersion !== NativeProxyControlResponseCipher::ProtocolVersion)) {
            return $this->unauthorizedResponse();
        }

        $allowedProxyIds = config('native_proxy.allowed_proxy_ids', []);

        if (is_array($allowedProxyIds) && $allowedProxyIds !== [] && ! in_array($proxyInstanceId, $allowedProxyIds, true)) {
            return $this->unauthorizedResponse();
        }

        $allowedProxyIps = config('native_proxy.allowed_proxy_ips', []);

        $socketPeerIp = $request->server('REMOTE_ADDR');

        if (is_array($allowedProxyIps) && $allowedProxyIps !== [] && (! is_string($socketPeerIp) || ! in_array($socketPeerIp, $allowedProxyIps, true))) {
            return $this->unauthorizedResponse();
        }

        if (abs((int) now()->timestamp - (int) $timestamp) > config('native_proxy.control_clock_skew_seconds')) {
            return $this->unauthorizedResponse();
        }

        $canonicalPayload = implode("\n", [
            $request->method(),
            '/'.ltrim($request->path(), '/'),
            $timestamp,
            $requestId,
            hash('sha256', $request->getContent()),
        ]);

        if (! hash_equals(hash_hmac('sha256', $canonicalPayload, $secret), $signature)) {
            return $this->unauthorizedResponse();
        }

        $protectResponse = $protocolVersion === NativeProxyControlResponseCipher::ProtocolVersion;

        if ($protectResponse && ! $request->isMethodSafe()) {
            try {
                $request->replace($this->responseCipher->decryptRequest(
                    $request,
                    $secret,
                    $proxyInstanceId,
                    $timestamp,
                    $requestId,
                ));
            } catch (Throwable) {
                return $this->unauthorizedResponse();
            }
        }

        if ($request->isMethodSafe()) {
            $response = $next($request);

            return $protectResponse
                ? $this->responseCipher->encrypt($response, $secret, $proxyInstanceId, $timestamp, $requestId)
                : $response;
        }

        $cacheKey = 'native-proxy:control-request:'.$requestId;
        $fingerprint = hash('sha256', implode("\n", [$proxyInstanceId, (string) $protocolVersion, $canonicalPayload]));
        $existing = Cache::get($cacheKey);

        if ($existing !== null) {
            return $this->replayResponse($existing, $fingerprint, $protectResponse, $secret, $proxyInstanceId, $timestamp, $requestId);
        }

        if (! Cache::add($cacheKey, ['fingerprint' => $fingerprint, 'status' => 'processing'], now()->addSeconds(config('native_proxy.control_nonce_ttl_seconds')))) {
            return $this->replayResponse(Cache::get($cacheKey), $fingerprint, $protectResponse, $secret, $proxyInstanceId, $timestamp, $requestId);
        }

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            Cache::forget($cacheKey);

            throw $exception;
        }

        if ($protectResponse) {
            $response = $this->responseCipher->encrypt($response, $secret, $proxyInstanceId, $timestamp, $requestId);
        }

        Cache::put($cacheKey, [
            'fingerprint' => $fingerprint,
            'status' => 'completed',
            'response_status' => $response->getStatusCode(),
            'payload' => Crypt::encryptString((string) $response->getContent()),
        ], now()->addSeconds(config('native_proxy.control_nonce_ttl_seconds')));

        return $response;
    }

    private function replayResponse(
        mixed $record,
        string $fingerprint,
        bool $protectResponse,
        string $secret,
        string $proxyInstanceId,
        string $timestamp,
        string $requestId,
    ): Response {
        if (! is_array($record) || ! isset($record['fingerprint']) || ! is_string($record['fingerprint']) || ! hash_equals($record['fingerprint'], $fingerprint)) {
            return $this->unauthorizedResponse();
        }

        if (($record['status'] ?? null) !== 'completed' || ! isset($record['response_status'], $record['payload']) || ! is_int($record['response_status']) || ! is_string($record['payload'])) {
            $response = response()->json(['message' => 'Request is already being processed.'], Response::HTTP_CONFLICT)
                ->header('Cache-Control', 'no-store, private');

            return $protectResponse
                ? $this->responseCipher->encrypt($response, $secret, $proxyInstanceId, $timestamp, $requestId)
                : $response;
        }

        try {
            $payload = Crypt::decryptString($record['payload']);
        } catch (DecryptException) {
            return $this->unauthorizedResponse();
        }

        $headers = [
            'Cache-Control' => 'no-store, private',
            'Content-Type' => 'application/json',
        ];

        if ($protectResponse) {
            $headers[NativeProxyControlResponseCipher::ProtocolHeader] = NativeProxyControlResponseCipher::ProtocolVersion;
        }

        return response($payload, $record['response_status'], $headers);
    }

    private function unauthorizedResponse(): JsonResponse
    {
        return response()->json(['message' => 'Unauthorized.'], 401);
    }
}
