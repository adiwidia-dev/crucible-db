<?php

namespace App\Http\Controllers\Internal\NativeProxy;

use App\Http\Controllers\Controller;
use App\Http\Requests\AuthenticateNativeProxyConnectionRequest;
use App\Http\Requests\NativeProxyConnectionLifecycleRequest;
use App\Http\Requests\ReportNativeProxyConnectionTrafficRequest;
use App\Models\NativeProxyConnection;
use App\Services\NativeProxy\ConnectionAdmission;
use Illuminate\Http\JsonResponse;

class ConnectionHeartbeatController extends Controller
{
    public function authenticated(NativeProxyConnection $nativeProxyConnection, AuthenticateNativeProxyConnectionRequest $request, ConnectionAdmission $admission): JsonResponse
    {
        $admission->assertOwnedByProxy($nativeProxyConnection, $request->validated('proxy_instance_id'));
        $admission->markAuthenticated($nativeProxyConnection, $request->clientMetadata());

        return $this->response('continue');
    }

    public function heartbeat(NativeProxyConnection $nativeProxyConnection, NativeProxyConnectionLifecycleRequest $request, ConnectionAdmission $admission): JsonResponse
    {
        $admission->assertOwnedByProxy($nativeProxyConnection, $request->validated('proxy_instance_id'));
        $decision = $admission->heartbeat($nativeProxyConnection);

        return $this->response($decision->reason, $decision->continueConnection);
    }

    public function destroy(NativeProxyConnection $nativeProxyConnection, NativeProxyConnectionLifecycleRequest $request, ConnectionAdmission $admission): JsonResponse
    {
        $admission->assertOwnedByProxy($nativeProxyConnection, $request->validated('proxy_instance_id'));
        $admission->close($nativeProxyConnection, $request->validated('reason') ?? 'Closed by native proxy.');

        return $this->response('closed');
    }

    public function traffic(ReportNativeProxyConnectionTrafficRequest $request, ConnectionAdmission $admission): JsonResponse
    {
        $nativeProxyConnection = NativeProxyConnection::query()
            ->where('proxy_connection_id', $request->validated('proxy_connection_id'))
            ->firstOrFail();
        $admission->assertOwnedByProxy($nativeProxyConnection, $request->validated('proxy_instance_id'));
        $admission->recordTraffic(
            $nativeProxyConnection,
            $request->integer('bytes_received'),
            $request->integer('bytes_sent'),
        );

        return $this->response('recorded');
    }

    private function response(string $status, bool $continue = true): JsonResponse
    {
        return response()
            ->json(['status' => $status, 'continue' => $continue])
            ->header('Cache-Control', 'no-store, private');
    }
}
