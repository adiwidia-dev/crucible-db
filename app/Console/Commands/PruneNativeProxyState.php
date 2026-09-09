<?php

namespace App\Console\Commands;

use App\Enums\ExecutionStatus;
use App\Enums\NativeProxyConnectionStatus;
use App\Enums\NativeProxyDeviceAuthorizationStatus;
use App\Models\NativeProxyAuthAttempt;
use App\Models\NativeProxyConnection;
use App\Models\NativeProxyDeviceAuthorization;
use App\Models\NativeProxyToken;
use App\Models\QueryExecution;
use App\Models\QuerySessionQuery;
use App\Services\ApplicationDatabaseMigrationFence;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('crucible:prune-native-proxy-state {--days=30 : Retention period for disposable native proxy control state.}')]
#[Description('Prune disposable native client authorization and abandoned execution state.')]
class PruneNativeProxyState extends Command
{
    public function handle(ApplicationDatabaseMigrationFence $fence): int
    {
        $ran = $fence->runScheduledMutation(fn (): bool => $this->prune());

        if (! $ran) {
            $this->info('Skipped native proxy pruning while an application database migration is fenced.');

            return self::SUCCESS;
        }

        return self::SUCCESS;
    }

    private function prune(): bool
    {
        $now = now();
        $cutoff = now()->subDays(max(1, (int) $this->option('days')));

        $expiredAuthorizations = NativeProxyDeviceAuthorization::query()
            ->whereIn('status', [NativeProxyDeviceAuthorizationStatus::Pending, NativeProxyDeviceAuthorizationStatus::Approved])
            ->where('expires_at', '<=', $now)
            ->update([
                'status' => NativeProxyDeviceAuthorizationStatus::Expired,
                'updated_at' => $now,
            ]);

        $reservations = NativeProxyConnection::query()
            ->where('status', NativeProxyConnectionStatus::Reserved)
            ->where('reservation_expires_at', '<=', $now)
            ->update([
                'status' => NativeProxyConnectionStatus::Failed,
                'reservation_expires_at' => null,
                'disconnected_at' => $now,
                'disconnect_reason' => 'Authentication reservation expired.',
                'updated_at' => $now,
            ]);
        $staleConnections = NativeProxyConnection::query()
            ->where('status', NativeProxyConnectionStatus::Active)
            ->where('last_activity_at', '<=', $now->copy()->subSeconds((int) config('native_proxy.connection_stale_seconds')))
            ->update([
                'status' => NativeProxyConnectionStatus::Failed,
                'disconnected_at' => $now,
                'disconnect_reason' => 'Native proxy connection heartbeat timed out.',
                'updated_at' => $now,
            ]);
        $statementCutoff = $now->copy()->subSeconds((int) config('native_proxy.statement_stale_seconds'));
        $abandonedSessionStatements = QuerySessionQuery::query()
            ->where('status', ExecutionStatus::Running)
            ->where('started_at', '<=', $statementCutoff)
            ->update([
                'status' => ExecutionStatus::Failed,
                'finished_at' => $now,
                'error_message' => 'Native proxy execution timed out before completion.',
                'updated_at' => $now,
            ]);
        $abandonedExecutions = QueryExecution::query()
            ->where('status', ExecutionStatus::Running)
            ->where('started_at', '<=', $statementCutoff)
            ->update([
                'status' => ExecutionStatus::Failed,
                'finished_at' => $now,
                'error_message' => 'Native proxy execution timed out before completion.',
                'updated_at' => $now,
            ]);

        $deviceAuthorizations = NativeProxyDeviceAuthorization::query()
            ->whereIn('status', [NativeProxyDeviceAuthorizationStatus::Denied, NativeProxyDeviceAuthorizationStatus::Expired, NativeProxyDeviceAuthorizationStatus::Consumed])
            ->where('updated_at', '<', $cutoff)
            ->whereDoesntHave('tokens', function ($query) use ($cutoff): void {
                $query->where(function ($query) use ($cutoff): void {
                    $query->whereNull('revoked_at')
                        ->where('expires_at', '>=', $cutoff);
                });
            })
            ->delete();
        $tokens = NativeProxyToken::query()
            ->where(function ($query) use ($cutoff): void {
                $query->where(function ($query) use ($cutoff): void {
                    $query->whereNotNull('revoked_at')->where('revoked_at', '<', $cutoff);
                })->orWhere('expires_at', '<', $cutoff);
            })
            ->delete();
        $attempts = NativeProxyAuthAttempt::query()
            ->where(function ($query) use ($cutoff): void {
                $query->where('expires_at', '<', $cutoff)
                    ->orWhere(function ($query) use ($cutoff): void {
                        $query->where('status', 'consumed')->where('consumed_at', '<', $cutoff);
                    });
            })
            ->delete();
        $connections = NativeProxyConnection::query()
            ->whereIn('status', [NativeProxyConnectionStatus::Closed, NativeProxyConnectionStatus::Revoked, NativeProxyConnectionStatus::Failed])
            ->where('disconnected_at', '<', $cutoff)
            ->delete();

        $this->info("Reconciled {$expiredAuthorizations} expired device authorization(s), {$reservations} expired reservation(s), {$staleConnections} stale connection(s), and ".($abandonedSessionStatements + $abandonedExecutions)." stale statement(s). Pruned {$deviceAuthorizations} device authorization(s), {$tokens} token(s), {$attempts} auth attempt(s), and {$connections} connection(s).");

        return true;
    }
}
