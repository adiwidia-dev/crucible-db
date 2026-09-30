<?php

namespace App\Http\Controllers;

use App\Models\QuerySessionQuery;
use App\Services\QueryResultSnapshotStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class QuerySessionQueryResultController extends Controller
{
    public function __invoke(QuerySessionQuery $querySessionQuery, QueryResultSnapshotStore $resultSnapshotStore): JsonResponse
    {
        $querySessionQuery->loadMissing('querySession');

        Gate::authorize('view', $querySessionQuery->querySession);
        abort_unless($querySessionQuery->querySession->isActive(), 404);

        $resultPage = $resultSnapshotStore->page(
            $querySessionQuery,
            request()->integer('page', 1),
        );

        abort_if($resultPage === null, 404);

        return response()->json($resultPage);
    }
}
