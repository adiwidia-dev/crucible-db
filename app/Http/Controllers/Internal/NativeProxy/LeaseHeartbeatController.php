<?php

namespace App\Http\Controllers\Internal\NativeProxy;

use App\Http\Controllers\Controller;
use App\Http\Requests\HeartbeatNativeProxyLeaseRequest;
use App\Services\NativeProxy\ConnectionAdmission;
use Illuminate\Http\JsonResponse;

class LeaseHeartbeatController extends Controller
{
    public function __invoke(HeartbeatNativeProxyLeaseRequest $request, ConnectionAdmission $admission): JsonResponse
    {
        $decision = $admission->heartbeatLease(
            $request->validated('lease_id'),
            $request->validated('device_authorization_id'),
            $request->validated('bearer_hash'),
        );

        return response()
            ->json(['status' => $decision->reason, 'continue' => $decision->continueConnection])
            ->header('Cache-Control', 'no-store, private');
    }
}
