<?php

namespace App\Http\Controllers\NativeProxy;

use App\Exceptions\NativeProxyCredentialsAlreadyCreatedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateNativeProxyCredentialsRequest;
use App\Http\Requests\RevokeNativeProxyLeaseRequest;
use App\Http\Requests\RotateNativeProxyCredentialsRequest;
use App\Models\NativeProxyLease;
use App\Models\QuerySession;
use App\Services\NativeProxy\LeaseWorkflow;
use App\Support\NativeProxyCredential;
use Illuminate\Http\JsonResponse;

class LeaseController extends Controller
{
    public function store(CreateNativeProxyCredentialsRequest $request, QuerySession $querySession, LeaseWorkflow $workflow): JsonResponse
    {
        try {
            $credential = $workflow->create(
                $querySession,
                $request->user(),
                $request->validated('idempotency_key'),
            );
        } catch (NativeProxyCredentialsAlreadyCreatedException) {
            return $this->credentialsAlreadyCreatedResponse();
        }

        return $this->credentialResponse($credential, 201);
    }

    public function rotate(RotateNativeProxyCredentialsRequest $request, NativeProxyLease $nativeProxyLease, LeaseWorkflow $workflow): JsonResponse
    {
        return $this->credentialResponse($workflow->rotate($nativeProxyLease, $request->user()));
    }

    public function revoke(RevokeNativeProxyLeaseRequest $request, NativeProxyLease $nativeProxyLease, LeaseWorkflow $workflow): JsonResponse
    {
        $workflow->revoke($nativeProxyLease, $request->user(), $request->validated('reason'));

        return response()->json(status: 204)->header('Cache-Control', 'no-store, private');
    }

    private function credentialResponse(NativeProxyCredential $credential, int $status = 200): JsonResponse
    {
        return response()
            ->json($credential->toArray(), $status)
            ->header('Cache-Control', 'no-store, private')
            ->header('Pragma', 'no-cache');
    }

    private function credentialsAlreadyCreatedResponse(): JsonResponse
    {
        return response()
            ->json([
                'code' => 'credentials_already_created',
                'message' => 'Temporary credentials have already been created for this session. Rotate them to create new credentials.',
            ], 409)
            ->header('Cache-Control', 'no-store, private');
    }
}
