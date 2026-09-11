<?php

namespace App\Http\Controllers\Internal\NativeProxy;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConsumeNativeProxyAuthAttemptRequest;
use App\Http\Requests\NativeProxyConnectionLifecycleRequest;
use App\Models\NativeProxyConnection;
use App\Services\NativeProxy\ConnectionAdmission;
use Illuminate\Http\JsonResponse;

class ConnectionController extends Controller
{
    public function store(ConsumeNativeProxyAuthAttemptRequest $request, ConnectionAdmission $admission): JsonResponse
    {
        $connection = $admission->authorizeConnection($request->toAdmissionData());

        return response()
            ->json([
                'connection_id' => $connection->connectionId,
                'lease_id' => $connection->leaseId,
                'user_id' => $connection->userId,
                'protocol' => $connection->protocol,
                'credential_version' => $connection->credentialVersion,
                'read_only' => $connection->readOnly,
            ])
            ->header('Cache-Control', 'no-store, private');
    }

    public function upstream(NativeProxyConnection $nativeProxyConnection, NativeProxyConnectionLifecycleRequest $request, ConnectionAdmission $admission): JsonResponse
    {
        $admission->assertOwnedByProxy($nativeProxyConnection, $request->validated('proxy_instance_id'));
        $upstream = $admission->upstreamMaterial($nativeProxyConnection);

        return response()
            ->json([
                'connection_id' => $nativeProxyConnection->id,
                'upstream' => [
                    'host' => $upstream->host,
                    'port' => $upstream->port,
                    'database' => $upstream->database,
                    'username' => $upstream->username,
                    'password' => $upstream->password,
                    'tls_mode' => $upstream->tlsMode,
                    'tls_ca_certificate' => $upstream->tlsCaCertificate,
                    'tls_client_certificate' => $upstream->tlsClientCertificate,
                    'tls_client_key' => $upstream->tlsClientKey,
                ],
            ])
            ->header('Cache-Control', 'no-store, private');
    }
}
