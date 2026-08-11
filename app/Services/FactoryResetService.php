<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class FactoryResetService
{
    /**
     * Reset Crucible's control-plane records without touching target databases.
     */
    public function reset(User $actor, ?string $ipAddress): void
    {
        $this->clearPendingQueues();

        DB::transaction(function (): void {
            foreach ($this->tablesToClear() as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->delete();
                }
            }
        });

        Log::warning('Crucible DB factory reset completed.', [
            'actor_id' => $actor->id,
            'ip_address' => $ipAddress,
        ]);
    }

    private function clearPendingQueues(): void
    {
        if (config('queue.default') !== 'redis') {
            return;
        }

        foreach (['queries', 'default'] as $queue) {
            $exitCode = Artisan::call('horizon:clear', [
                'connection' => 'redis',
                '--queue' => $queue,
                '--force' => true,
            ]);

            if ($exitCode !== 0) {
                throw new RuntimeException(sprintf('Unable to clear the %s queue.', $queue));
            }
        }
    }

    /**
     * @return list<string>
     */
    private function tablesToClear(): array
    {
        return [
            'query_session_queries',
            'query_sessions',
            'query_reviews',
            'query_executions',
            'query_requests',
            'role_database_permissions',
            'database_connections',
            'user_identities',
            'passkeys',
            'role_user',
            'auth_providers',
            'password_reset_tokens',
            'sessions',
            'audit_logs',
            'failed_jobs',
            'job_batches',
            'jobs',
            'application_settings',
            'users',
            'roles',
        ];
    }
}
