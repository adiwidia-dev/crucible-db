<?php

namespace App\Http\Controllers\NativeProxy;

use App\Http\Controllers\Controller;
use App\Http\Requests\ApproveNativeProxyDeviceRequest;
use App\Http\Requests\ResolveNativeProxyDeviceRequest;
use App\Models\NativeProxyDeviceAuthorization;
use App\Services\NativeProxy\DeviceAuthorizationWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class DeviceAuthorizationController extends Controller
{
    public function confirm(): Response
    {
        return Inertia::render('native-proxy/device-authorization', [
            'authorization' => null,
        ]);
    }

    /**
     * @throws ValidationException
     */
    public function resolve(ResolveNativeProxyDeviceRequest $request, DeviceAuthorizationWorkflow $workflow): RedirectResponse
    {
        $userCode = $request->validated('user_code');
        $authorization = $workflow->findByUserCodeFor($request->user(), $userCode);

        if ($authorization === null) {
            throw ValidationException::withMessages([
                'user_code' => 'That device code is not valid.',
            ]);
        }

        try {
            $workflow->approve($authorization, $request->user());
        } catch (ValidationException) {
            throw ValidationException::withMessages([
                'user_code' => 'That device code is no longer available for approval.',
            ]);
        }

        return redirect()->route('native-proxy.device-authorizations.show', $authorization);
    }

    public function show(NativeProxyDeviceAuthorization $deviceAuthorization): Response
    {
        Gate::authorize('manageNativeProxy', $deviceAuthorization->lease->querySession);

        return Inertia::render('native-proxy/device-authorization', [
            'authorization' => $this->authorization($deviceAuthorization),
        ]);
    }

    public function decide(ApproveNativeProxyDeviceRequest $request, NativeProxyDeviceAuthorization $deviceAuthorization, DeviceAuthorizationWorkflow $workflow): RedirectResponse
    {
        if ($request->validated('decision') === 'approve') {
            $workflow->approve($deviceAuthorization, $request->user());
        } else {
            $workflow->deny($deviceAuthorization, $request->user());
        }

        return redirect()->route('native-proxy.device-authorizations.show', $deviceAuthorization);
    }

    /**
     * @return array{id:string, code_status:string, connection:string, protocol:string, access_mode:string, cli_version:string, operating_system:string, architecture:string, device_label:string|null, expires_at:string}
     */
    private function authorization(NativeProxyDeviceAuthorization $authorization): array
    {
        $authorization->loadMissing('lease.querySession.queryRequest', 'lease.databaseConnection');

        return [
            'id' => $authorization->id,
            'code_status' => $authorization->status->value,
            'connection' => $authorization->lease->databaseConnection->name,
            'protocol' => $authorization->lease->protocol->value,
            'access_mode' => $authorization->lease->access_mode->value,
            'cli_version' => $authorization->cli_version,
            'operating_system' => $authorization->operating_system,
            'architecture' => $authorization->architecture,
            'device_label' => $authorization->device_label,
            'expires_at' => $authorization->expires_at->toIso8601String(),
        ];
    }
}
