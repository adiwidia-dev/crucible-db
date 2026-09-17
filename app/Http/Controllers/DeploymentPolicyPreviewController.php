<?php

namespace App\Http\Controllers;

use App\Http\Requests\PreviewDeploymentPolicyRequest;
use App\Models\DatabaseConnection;
use App\Services\DeploymentPolicyPreview;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class DeploymentPolicyPreviewController extends Controller
{
    public function __invoke(PreviewDeploymentPolicyRequest $request, DeploymentPolicyPreview $preview): JsonResponse
    {
        /** @var array<int, array{sql:string,database_connection_id:int}> $statements */
        $statements = $request->validated('statements');
        $connections = DatabaseConnection::query()
            ->whereKey(collect($statements)->pluck('database_connection_id')->unique())
            ->get()
            ->keyBy('id');

        $connections->each(fn (DatabaseConnection $connection) => Gate::authorize('view', $connection));

        return response()->json([
            'statements' => array_map(
                fn (array $statement): array => $preview->forStatement($statement['sql'], $connections->get($statement['database_connection_id'])),
                $statements,
            ),
        ])->header('Cache-Control', 'no-store');
    }
}
