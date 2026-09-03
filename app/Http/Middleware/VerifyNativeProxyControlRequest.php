<?php

namespace App\Http\Middleware;

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

        if (! config('native_proxy.enabled') || ! is_string($secret) || $secret === '' || ! is_string($proxyInstanceId) || ! preg_match('/^[A-Za-z0-9._-]{1,128}$/', $proxyInstanceId) || ! is_string($timestamp) || ! ctype_digit($timestamp) || ! is_string($requestId) || ! preg_match('/^[A-Za-z0-9_-]{16,128}$/', $requestId) || ! is_string($signature) || ! preg_match('/^[a-f0-9]{64}$/i', $signature)) {
            return $this->unauthorizedResponse();
        }

        if (abs(now()->timestamp - (int) $timestamp) > config('native_proxy.control_clock_skew_seconds')) {
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

        if ($request->isMethodSafe()) {
            return $next($request);
        }

        $cacheKey = 'native-proxy:control-request:'.$requestId;
        $fingerprint = hash('sha256', implode("\n", [$proxyInstanceId, $canonicalPayload]));
        $existing = Cache::get($cacheKey);

        if ($existing !== null) {
            return $this->replayResponse($existing, $fingerprint);
        }

        if (! Cache::add($cacheKey, ['fingerprint' => $fingerprint, 'status' => 'processing'], now()->addSeconds(config('native_proxy.control_nonce_ttl_seconds')))) {
            return $this->replayResponse(Cache::get($cacheKey), $fingerprint);
        }

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            Cache::forget($cacheKey);

            throw $exception;
        }

        Cache::put($cacheKey, [
            'fingerprint' => $fingerprint,
            'status' => 'completed',
            'response_status' => $response->getStatusCode(),
            'payload' => Crypt::encryptString((string) $response->getContent()),
        ], now()->addSeconds(config('native_proxy.control_nonce_ttl_seconds')));

        return $response;
    }

    private function replayResponse(mixed $record, string $fingerprint): Response
    {
        if (! is_array($record) || ! isset($record['fingerprint']) || ! is_string($record['fingerprint']) || ! hash_equals($record['fingerprint'], $fingerprint)) {
            return $this->unauthorizedResponse();
        }

        if (($record['status'] ?? null) !== 'completed' || ! isset($record['response_status'], $record['payload']) || ! is_int($record['response_status']) || ! is_string($record['payload'])) {
            return response()->json(['message' => 'Request is already being processed.'], Response::HTTP_CONFLICT)
                ->header('Cache-Control', 'no-store, private');
        }

        try {
            $payload = Crypt::decryptString($record['payload']);
        } catch (DecryptException) {
            return $this->unauthorizedResponse();
        }

        return response($payload, $record['response_status'], [
            'Cache-Control' => 'no-store, private',
            'Content-Type' => 'application/json',
        ]);
    }

    private function unauthorizedResponse(): JsonResponse
    {
        return response()->json(['message' => 'Unauthorized.'], 401);
    }
}
