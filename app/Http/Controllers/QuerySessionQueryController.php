<?php

namespace App\Http\Controllers;

use App\Enums\ExecutionStatus;
use App\Http\Requests\StoreQuerySessionQueryRequest;
use App\Models\QuerySession;
use App\Services\QuerySessionWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class QuerySessionQueryController extends Controller
{
    public function store(StoreQuerySessionQueryRequest $request, QuerySession $querySession, QuerySessionWorkflow $workflow): RedirectResponse
    {
        try {
            $execution = $workflow->execute($querySession, $request->user(), $request->string('sql')->toString());
        } catch (ValidationException $exception) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => $exception->errors()['sql'][0] ?? 'Query could not be executed.',
            ]);

            throw $exception;
        }

        if ($execution['query']->status === ExecutionStatus::Failed) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => 'Query failed. Check the result panel for details.',
            ]);

            return back()->withErrors([
                'sql' => 'Query failed. Check the result panel for details.',
            ]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Query executed.',
        ]);

        return back();
    }
}
