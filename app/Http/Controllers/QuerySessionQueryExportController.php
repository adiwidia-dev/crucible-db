<?php

namespace App\Http\Controllers;

use App\Models\QuerySessionQuery;
use App\Services\AuditLogger;
use App\Services\QueryResultSnapshotStore;
use App\Support\CsvDownload;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class QuerySessionQueryExportController extends Controller
{
    public function __invoke(QuerySessionQuery $querySessionQuery, AuditLogger $auditLogger, CsvDownload $csvDownload, QueryResultSnapshotStore $resultSnapshotStore): StreamedResponse
    {
        $querySessionQuery->loadMissing('querySession');

        Gate::authorize('view', $querySessionQuery->querySession);
        abort_unless($querySessionQuery->querySession->isActive(), 404);

        $auditLogger->log('query_session_query.exported', request()->user(), $querySessionQuery, [
            'query_session_query_id' => $querySessionQuery->id,
            'query_session_id' => $querySessionQuery->query_session_id,
            'row_count' => $querySessionQuery->row_count,
            'status' => $querySessionQuery->status->value,
        ]);

        return $csvDownload->sampleRows(
            sprintf('query-session-query-%d-result.csv', $querySessionQuery->id),
            $resultSnapshotStore->rows($querySessionQuery),
        );
    }
}
