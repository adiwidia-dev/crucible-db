<?php

namespace App\Services;

use App\Models\QueryRequest;
use App\Models\QueryResultSnapshot;
use App\Models\QuerySession;
use App\Models\QuerySessionQuery;
use Carbon\CarbonInterface;

class QueryResultSnapshotStore
{
    public const int PageSize = 100;

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function store(QuerySessionQuery $querySessionQuery, array $rows, int $byteCount, CarbonInterface $expiresAt): QueryResultSnapshot
    {
        return QueryResultSnapshot::query()->updateOrCreate(
            ['query_session_query_id' => $querySessionQuery->id],
            [
                'rows' => $rows,
                'row_count' => count($rows),
                'byte_count' => $byteCount,
                'expires_at' => $expiresAt,
            ],
        );
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, meta: array{current_page: int, from: int|null, last_page: int, per_page: int, to: int|null, total: int}}|null
     */
    public function page(QuerySessionQuery $querySessionQuery, int $page): ?array
    {
        $snapshot = $querySessionQuery->resultSnapshot()
            ->where('expires_at', '>', now())
            ->first();

        if ($snapshot === null) {
            return null;
        }

        $rows = $snapshot->rows;
        $total = count($rows);
        $lastPage = max(1, (int) ceil($total / self::PageSize));
        $currentPage = min(max($page, 1), $lastPage);
        $data = array_slice($rows, ($currentPage - 1) * self::PageSize, self::PageSize);

        return [
            'data' => $data,
            'meta' => [
                'current_page' => $currentPage,
                'from' => $data === [] ? null : (($currentPage - 1) * self::PageSize) + 1,
                'last_page' => $lastPage,
                'per_page' => self::PageSize,
                'to' => $data === [] ? null : (($currentPage - 1) * self::PageSize) + count($data),
                'total' => $total,
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function rows(QuerySessionQuery $querySessionQuery): array
    {
        $snapshot = $querySessionQuery->resultSnapshot()
            ->where('expires_at', '>', now())
            ->first();

        return $snapshot === null ? $querySessionQuery->sample_rows : $snapshot->rows;
    }

    public function forgetForSession(QuerySession $querySession): void
    {
        QueryResultSnapshot::query()
            ->whereHas('querySessionQuery', fn ($query) => $query->whereBelongsTo($querySession))
            ->delete();
    }

    public function forgetForQueryRequest(QueryRequest $queryRequest): void
    {
        QueryResultSnapshot::query()
            ->whereHas(
                'querySessionQuery.querySession',
                fn ($query) => $query->where('query_request_id', $queryRequest->id),
            )
            ->delete();
    }
}
