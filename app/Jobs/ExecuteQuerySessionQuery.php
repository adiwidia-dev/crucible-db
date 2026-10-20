<?php

namespace App\Jobs;

use App\Services\QuerySessionWorkflow;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\FailOnTimeout;
use Throwable;

#[FailOnTimeout]
class ExecuteQuerySessionQuery implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    /**
     * Create a new job instance.
     */
    public function __construct(public readonly int $querySessionQueryId)
    {
        $this->timeout = (int) config('query_access.worker_timeout_seconds');
    }

    /**
     * Execute the job.
     */
    public function handle(QuerySessionWorkflow $workflow): void
    {
        $workflow->executeQueued($this->querySessionQueryId);
    }

    public function failed(?Throwable $exception): void
    {
        app(QuerySessionWorkflow::class)->markInterrupted(
            $this->querySessionQueryId,
            $exception ?? new \RuntimeException('Query execution job failed.'),
        );
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return ['query-session-query:'.$this->querySessionQueryId];
    }
}
