<?php

namespace App\Http\Controllers\Internal\NativeProxy;

use App\Http\Controllers\Controller;
use App\Http\Requests\AuthorizeNativeProxyTunnelRequest;
use App\Services\NativeProxy\ConnectionAdmission;
use Illuminate\Http\JsonResponse;

class TunnelAuthorizationController extends Controller
{
    public function __invoke(AuthorizeNativeProxyTunnelRequest $request, ConnectionAdmission $admission): JsonResponse
    {
        $authorization = $admission->authorizeTunnel($request->toAuthorizationData());

        return response()
            ->json([
                'auth_attempt_id' => $authorization->authAttemptId,
                'lease_id' => $authorization->leaseId,
                'proxy_connection_id' => $authorization->proxyConnectionId,
                'protocol' => $authorization->protocol,
                'credential_version' => $authorization->credentialVersion,
                'protocol_authentication_secret' => $authorization->protocolAuthenticationSecret,
            ])
            ->header('Cache-Control', 'no-store, private');
    }
}
