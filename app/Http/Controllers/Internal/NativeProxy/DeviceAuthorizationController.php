<?php

namespace App\Http\Controllers\Internal\NativeProxy;

use App\Http\Controllers\Controller;
use App\Http\Requests\PollNativeProxyDeviceAuthorizationRequest;
use App\Http\Requests\StartNativeProxyDeviceAuthorizationRequest;
use App\Models\NativeProxyLease;
use App\Services\NativeProxy\DeviceAuthorizationWorkflow;
use Illuminate\Http\JsonResponse;

class DeviceAuthorizationController extends Controller
{
    public function store(StartNativeProxyDeviceAuthorizationRequest $request, DeviceAuthorizationWorkflow $workflow): JsonResponse
    {
        $lease = NativeProxyLease::query()->findOrFail($request->string('lease_id')->toString());
        $challenge = $workflow->begin($lease, [
            'cli_version' => $request->string('cli_version')->toString(),
            'operating_system' => $request->string('operating_system')->toString(),
            'architecture' => $request->string('architecture')->toString(),
            'device_label' => $request->filled('device_label')
                ? $request->string('device_label')->toString()
                : null,
        ]);
        $verificationUri = $this->publicApplicationRoute('native-proxy.device-authorizations.confirm');

        return response()
            ->json([
                'device_code' => $challenge->deviceCode,
                'user_code' => $challenge->userCode,
                'verification_uri' => $verificationUri,
                'verification_uri_complete' => $this->publicApplicationRoute('native-proxy.device-authorizations.confirm', [
                    'user_code' => $challenge->userCode,
                ]),
                'expires_in' => (int) $challenge->authorization->expires_at->diffInSeconds(now()),
                'interval' => $challenge->authorization->polling_interval_seconds,
                'protocol' => $lease->protocol->nativeProxyProtocol(),
            ], 201)
            ->header('Cache-Control', 'no-store, private');
    }

    public function token(PollNativeProxyDeviceAuthorizationRequest $request, DeviceAuthorizationWorkflow $workflow): JsonResponse
    {
        $result = $workflow->poll($request->validated('device_code'));

        if ($result->isIssued()) {
            return response()
                ->json([
                    'access_token' => $result->token,
                    'token_type' => 'Bearer',
                    'expires_in' => $result->expiresIn,
                    'device_authorization_id' => $result->deviceAuthorizationId,
                ])
                ->header('Cache-Control', 'no-store, private');
        }

        return response()
            ->json([
                'error' => $result->status,
                'interval' => $result->pollingIntervalSeconds,
            ], 400)
            ->header('Cache-Control', 'no-store, private');
    }

    /**
     * @param  array<string, string>  $parameters
     */
    private function publicApplicationRoute(string $name, array $parameters = []): string
    {
        return rtrim((string) config('app.url'), '/').route($name, $parameters, false);
    }
}
