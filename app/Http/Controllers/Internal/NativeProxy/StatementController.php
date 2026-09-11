<?php

namespace App\Http\Controllers\Internal\NativeProxy;

use App\Http\Controllers\Controller;
use App\Http\Requests\CompleteNativeProxyStatementRequest;
use App\Http\Requests\NativeProxyStatementRequest;
use App\Models\NativeProxyConnection;
use App\Models\QuerySessionQuery;
use App\Services\NativeProxy\ConnectionAdmission;
use App\Services\NativeProxy\StatementWorkflow;
use Illuminate\Http\JsonResponse;

class StatementController extends Controller
{
    public function validateTemplate(NativeProxyConnection $nativeProxyConnection, NativeProxyStatementRequest $request, ConnectionAdmission $admission, StatementWorkflow $workflow): JsonResponse
    {
        $admission->assertOwnedByProxy($nativeProxyConnection, $request->validated('proxy_instance_id'));
        $decision = $workflow->validateTemplate($nativeProxyConnection, $request->toStatementData());

        return $this->response([
            'allowed' => $decision->allowed,
            'code' => $decision->code,
            'message' => $decision->message,
            'session_command' => $decision->sessionCommand,
            'query_type' => $decision->queryType?->value,
            'result_filter' => $decision->resultFilter,
        ]);
    }

    public function authorizeExecution(NativeProxyConnection $nativeProxyConnection, NativeProxyStatementRequest $request, ConnectionAdmission $admission, StatementWorkflow $workflow): JsonResponse
    {
        $admission->assertOwnedByProxy($nativeProxyConnection, $request->validated('proxy_instance_id'));
        $statement = $workflow->authorizeExecution($nativeProxyConnection, $request->toStatementData());

        return $this->response(['statement_id' => $statement->id], 201);
    }

    public function complete(NativeProxyConnection $nativeProxyConnection, QuerySessionQuery $querySessionQuery, CompleteNativeProxyStatementRequest $request, ConnectionAdmission $admission, StatementWorkflow $workflow): JsonResponse
    {
        $admission->assertOwnedByProxy($nativeProxyConnection, $request->validated('proxy_instance_id'));
        abort_unless($querySessionQuery->native_proxy_connection_id === $nativeProxyConnection->id, 404);
        $workflow->complete($querySessionQuery, $request->toOutcome());

        return $this->response(['statement_id' => $querySessionQuery->id, 'completed' => true]);
    }

    /** @param array<string, mixed> $payload */
    private function response(array $payload, int $status = 200): JsonResponse
    {
        return response()
            ->json($payload, $status)
            ->header('Cache-Control', 'no-store, private');
    }
}
