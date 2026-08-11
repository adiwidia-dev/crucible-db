<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Services\AuditLogger;
use App\Support\CsvDownload;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditLogController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', AuditLog::class);

        $filters = $this->filters($request);

        $auditLogs = $this->queryForFilters($filters)
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('audit-logs/index', [
            'audit_logs' => $auditLogs
                ->through(fn (AuditLog $auditLog): array => [
                    'id' => $auditLog->id,
                    'action' => $auditLog->action,
                    'actor' => $auditLog->actor_id ? $auditLog->actor->name : 'System',
                    'auditable_type' => $auditLog->auditable_type,
                    'auditable_id' => $auditLog->auditable_id,
                    'ip_address' => $auditLog->ip_address,
                    'metadata' => $auditLog->metadata,
                    'created_at' => $auditLog->created_at?->toIso8601String(),
                ]),
            'filters' => $filters,
            'filter_options' => [
                'actions' => AuditLog::query()
                    ->select('action')
                    ->distinct()
                    ->orderBy('action')
                    ->pluck('action')
                    ->values(),
            ],
        ]);
    }

    public function export(Request $request, AuditLogger $auditLogger, CsvDownload $csvDownload): StreamedResponse
    {
        Gate::authorize('viewAny', AuditLog::class);

        $filters = $this->filters($request);
        $auditLogger->log('audit_logs.exported', $request->user(), null, [
            'filters' => $filters,
        ]);

        $rows = $this->queryForFilters($filters)
            ->latest()
            ->cursor()
            ->map(fn (AuditLog $auditLog): array => [
                $auditLog->created_at?->toIso8601String(),
                $auditLog->action,
                $auditLog->actor_id ? $auditLog->actor?->name : 'System',
                $auditLog->auditable_type,
                $auditLog->auditable_id,
                $auditLog->ip_address,
                json_encode($auditLog->metadata ?? [], JSON_THROW_ON_ERROR),
            ]);

        return $csvDownload->rows(
            'crucible-audit-logs-'.now()->format('Ymd-His').'.csv',
            ['created_at', 'action', 'actor', 'auditable_type', 'auditable_id', 'ip_address', 'metadata'],
            $rows,
        );
    }

    /**
     * @return array{search: string, action: string, actor: string, ip_address: string}
     */
    private function filters(Request $request): array
    {
        return [
            'search' => $request->string('search')->trim()->toString(),
            'action' => $request->string('action')->trim()->toString(),
            'actor' => $request->string('actor')->trim()->toString(),
            'ip_address' => $request->string('ip_address')->trim()->toString(),
        ];
    }

    /**
     * @param  array{search: string, action: string, actor: string, ip_address: string}  $filters
     * @return Builder<AuditLog>
     */
    private function queryForFilters(array $filters): Builder
    {
        return AuditLog::query()
            ->with('actor')
            ->when($filters['search'] !== '', function (Builder $query) use ($filters): void {
                $search = $filters['search'];

                $query->where(function (Builder $query) use ($search): void {
                    $query
                        ->where('action', 'like', "%{$search}%")
                        ->orWhere('auditable_type', 'like', "%{$search}%")
                        ->orWhere('ip_address', 'like', "%{$search}%")
                        ->orWhere('metadata', 'like', "%{$search}%")
                        ->orWhereHas('actor', function (Builder $query) use ($search): void {
                            $query
                                ->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                        });
                });
            })
            ->when($filters['action'] !== '', function (Builder $query) use ($filters): void {
                $query->where('action', $filters['action']);
            })
            ->when($filters['actor'] !== '', function (Builder $query) use ($filters): void {
                $actor = $filters['actor'];

                if (str($actor)->lower()->toString() === 'system') {
                    $query->whereNull('actor_id');

                    return;
                }

                $query->whereHas('actor', function (Builder $query) use ($actor): void {
                    $query
                        ->where('name', 'like', "%{$actor}%")
                        ->orWhere('email', 'like', "%{$actor}%");
                });
            })
            ->when($filters['ip_address'] !== '', function (Builder $query) use ($filters): void {
                $query->where('ip_address', 'like', "%{$filters['ip_address']}%");
            });
    }
}
