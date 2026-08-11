<?php

namespace App\Http\Controllers;

use App\Models\QueryExecution;
use App\Services\AuditLogger;
use App\Support\CsvDownload;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class QueryExecutionExportController extends Controller
{
    public function __invoke(QueryExecution $queryExecution, AuditLogger $auditLogger, CsvDownload $csvDownload): StreamedResponse
    {
        $queryExecution->loadMissing('queryRequest');

        Gate::authorize('view', $queryExecution->queryRequest);

        $auditLogger->log('query_execution.exported', request()->user(), $queryExecution, [
            'query_execution_id' => $queryExecution->id,
            'query_request_id' => $queryExecution->query_request_id,
            'row_count' => $queryExecution->row_count,
            'status' => $queryExecution->status->value,
        ]);

        return $csvDownload->sampleRows(
            sprintf('query-execution-%d-result.csv', $queryExecution->id),
            $queryExecution->sample_rows,
        );
    }
}
