<?php

namespace App\Http\Controllers;

use App\Models\QuerySessionQuery;
use App\Services\AuditLogger;
use App\Support\CsvDownload;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class QuerySessionQueryExportController extends Controller
{
    public function __invoke(QuerySessionQuery $querySessionQuery, AuditLogger $auditLogger, CsvDownload $csvDownload): StreamedResponse
    {
        $querySessionQuery->loadMissing('querySession');

        Gate::authorize('view', $querySessionQuery->querySession);

        $auditLogger->log('query_session_query.exported', request()->user(), $querySessionQuery, [
            'query_session_query_id' => $querySessionQuery->id,
            'query_session_id' => $querySessionQuery->query_session_id,
            'row_count' => $querySessionQuery->row_count,
            'status' => $querySessionQuery->status->value,
        ]);

        return $csvDownload->sampleRows(
            sprintf('query-session-query-%d-result.csv', $querySessionQuery->id),
            $querySessionQuery->sample_rows,
        );
    }
}
