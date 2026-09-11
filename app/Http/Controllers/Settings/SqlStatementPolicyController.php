<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateSqlStatementPolicyRequest;
use App\Models\ConnectionGroup;
use App\Models\DatabaseConnection;
use App\Models\SqlPolicyCandidate;
use App\Models\SqlPolicyRule;
use App\Services\ApplicationSettings;
use App\Services\AuditLogger;
use App\Services\NativeProxy\LeaseWorkflow;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class SqlStatementPolicyController extends Controller
{
    public function edit(ApplicationSettings $settings): Response
    {
        abort_unless(request()->user()->isAdmin(), 403);

        $connectionGroups = ConnectionGroup::query()
            ->with('databaseConnections:id')
            ->orderBy('name')
            ->get();
        $connections = DatabaseConnection::query()
            ->orderBy('name')
            ->get(['id', 'name']);
        $connectionNames = $connections->pluck('name', 'id');
        $groupNames = $connectionGroups->pluck('name', 'id');

        $candidates = SqlPolicyCandidate::query()
            ->whereNull('resolution')
            ->with([
                'occurrences' => fn ($query) => $query
                    ->with(['queryRequest:id,title', 'databaseConnection:id,name'])
                    ->latest('last_seen_at'),
            ])
            ->withCount('occurrences')
            ->latest('last_seen_at')
            ->paginate(10, ['*'], 'candidates_page')
            ->withQueryString()
            ->through(function (SqlPolicyCandidate $candidate) use ($connectionGroups): array {
                $observedConnectionIds = $candidate->occurrences
                    ->pluck('database_connection_id')
                    ->filter()
                    ->unique()
                    ->values();
                $scopeOptions = collect([
                    $observedConnectionIds->count() === 1 ? [
                        'type' => 'database_connection',
                        'id' => $observedConnectionIds->first(),
                        'label' => 'Only '.$candidate->occurrences->firstWhere('database_connection_id', $observedConnectionIds->first())?->databaseConnection?->name,
                    ] : null,
                    ...$connectionGroups
                        ->filter(function (ConnectionGroup $group) use ($observedConnectionIds): bool {
                            $groupConnectionIds = $group->databaseConnections->pluck('id');

                            return $observedConnectionIds->isNotEmpty()
                                && $observedConnectionIds->diff($groupConnectionIds)->isEmpty();
                        })
                        ->map(fn (ConnectionGroup $group): array => [
                            'type' => 'connection_group',
                            'id' => $group->id,
                            'label' => $group->name.' connection group',
                        ])
                        ->all(),
                    [
                        'type' => 'workspace',
                        'id' => null,
                        'label' => 'Entire workspace',
                    ],
                ])->filter()->values();

                return [
                    'id' => $candidate->id,
                    'driver' => $candidate->database_driver->value,
                    'canonical_sql' => $candidate->canonical_sql,
                    'shape_label' => $candidate->shape_label,
                    'shape_available' => $candidate->shape_signature !== null,
                    'occurrences_count' => $candidate->occurrences_count,
                    'first_seen_at' => $candidate->first_seen_at->toIso8601String(),
                    'last_seen_at' => $candidate->last_seen_at->toIso8601String(),
                    'occurrences' => $candidate->occurrences->take(5)->map(fn ($occurrence): array => [
                        'request_id' => $occurrence->query_request_id,
                        'request_title' => $occurrence->queryRequest?->title,
                        'connection_name' => $occurrence->databaseConnection?->name,
                    ])->values(),
                    'scope_options' => $scopeOptions,
                ];
            });

        $rules = SqlPolicyRule::query()
            ->with('createdBy:id,name')
            ->latest()
            ->get()
            ->map(fn (SqlPolicyRule $rule): array => [
                'id' => $rule->id,
                'driver' => $rule->database_driver->value,
                'effect' => $rule->effect->value,
                'match_type' => $rule->match_type->value,
                'statement' => $rule->shape_label ?? $rule->canonical_sql,
                'scope' => match ($rule->scope_type->value) {
                    'database_connection' => $connectionNames->get($rule->scope_id, 'Removed connection'),
                    'connection_group' => $groupNames->get($rule->scope_id, 'Removed connection group'),
                    default => 'Entire workspace',
                },
                'created_by' => $rule->createdBy->name,
                'is_enabled' => $rule->is_enabled,
                'created_at' => $rule->created_at?->toIso8601String(),
            ]);

        return Inertia::render('settings/admin/sql-policy', [
            'settings' => $settings->sqlStatementPolicyFormValues(),
            'candidates' => $candidates,
            'custom_rules' => $rules,
            'selected_candidate_id' => request()->integer('candidate') ?: null,
        ]);
    }

    public function update(UpdateSqlStatementPolicyRequest $request, ApplicationSettings $settings, AuditLogger $auditLogger, LeaseWorkflow $leaseWorkflow): RedirectResponse
    {
        $before = $settings->sqlStatementPolicyFormValues();
        $settings->put($request->validated());
        $after = $settings->sqlStatementPolicyFormValues();

        if ($before !== $after) {
            $leaseWorkflow->revokeAllActive($request->user(), 'Workspace SQL statement policy changed.');
        }

        $auditLogger->log('sql_statement_policy.updated', $request->user(), null, [
            'before' => $before,
            'after' => $after,
        ]);

        if ($before['sql_emergency_fallback_enabled'] !== $settings->allowsEmergencySqlFallback()) {
            $auditLogger->log('sql_statement_policy.emergency_fallback_toggled', $request->user(), null, [
                'enabled' => $settings->allowsEmergencySqlFallback(),
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'SQL policy updated.']);

        return back();
    }
}
