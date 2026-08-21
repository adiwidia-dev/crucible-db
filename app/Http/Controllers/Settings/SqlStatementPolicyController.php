<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateSqlStatementPolicyRequest;
use App\Services\ApplicationSettings;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class SqlStatementPolicyController extends Controller
{
    public function edit(ApplicationSettings $settings): Response
    {
        abort_unless(request()->user()->isAdmin(), 403);

        return Inertia::render('settings/admin/sql-policy', [
            'settings' => $settings->sqlStatementPolicyFormValues(),
        ]);
    }

    public function update(UpdateSqlStatementPolicyRequest $request, ApplicationSettings $settings, AuditLogger $auditLogger): RedirectResponse
    {
        $before = $settings->sqlStatementPolicyFormValues();
        $settings->put($request->validated());

        $auditLogger->log('sql_statement_policy.updated', $request->user(), null, [
            'before' => $before,
            'after' => $settings->sqlStatementPolicyFormValues(),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'SQL policy updated.']);

        return back();
    }
}
