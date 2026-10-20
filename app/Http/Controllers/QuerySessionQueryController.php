<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreQuerySessionQueryRequest;
use App\Models\DatabaseConnection;
use App\Models\QuerySession;
use App\Models\QuerySessionQuery;
use App\Services\QuerySessionWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class QuerySessionQueryController extends Controller
{
    public function store(StoreQuerySessionQueryRequest $request, QuerySession $querySession, QuerySessionWorkflow $workflow): RedirectResponse
    {
        try {
            $databaseConnection = DatabaseConnection::query()->findOrFail(
                $request->integer('database_connection_id'),
            );
            $workflow->queue(
                $querySession,
                $request->user(),
                $request->string('sql')->toString(),
                $databaseConnection,
            );
        } catch (ValidationException $exception) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => $exception->errors()['sql'][0] ?? 'Query could not be executed.',
            ]);

            throw $exception;
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Query queued for execution.',
        ]);

        return back();
    }

    public function cancel(QuerySessionQuery $querySessionQuery, QuerySessionWorkflow $workflow): RedirectResponse
    {
        $querySessionQuery->loadMissing('querySession');
        Gate::authorize('submitQuery', $querySessionQuery->querySession);

        $query = $workflow->cancel($querySessionQuery, request()->user());

        Inertia::flash('toast', [
            'type' => 'info',
            'message' => $query->status->isTerminal()
                ? 'Query cancelled.'
                : 'Query cancellation requested.',
        ]);

        return back();
    }
}
