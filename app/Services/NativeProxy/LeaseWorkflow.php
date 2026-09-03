<?php

namespace App\Services\NativeProxy;

use App\Enums\AccessMode;
use App\Enums\AccessTransport;
use App\Enums\NativeProxyConnectionStatus;
use App\Enums\NativeProxyLeaseStatus;
use App\Enums\QueryRequestKind;
use App\Enums\QueryType;
use App\Events\NativeProxyCredentialsCreated;
use App\Events\NativeProxyLeaseRevoked;
use App\Exceptions\NativeProxyCredentialsAlreadyCreatedException;
use App\Models\NativeProxyAuthAttempt;
use App\Models\NativeProxyConnection;
use App\Models\NativeProxyLease;
use App\Models\NativeProxyToken;
use App\Models\QueryRequest;
use App\Models\QuerySession;
use App\Models\User;
use App\Services\ApplicationSettings;
use App\Services\AuditLogger;
use App\Support\NativeProxyCredential;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class LeaseWorkflow
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly ApplicationSettings $applicationSettings,
    ) {}

    /**
     * @throws NativeProxyCredentialsAlreadyCreatedException
     * @throws ValidationException
     */
    public function create(QuerySession $session, User $actor, string $idempotencyKey): NativeProxyCredential
    {
        try {
            return DB::transaction(function () use ($session, $actor, $idempotencyKey): NativeProxyCredential {
                $lockedSession = QuerySession::query()
                    ->with(['queryRequest', 'databaseConnection'])
                    ->lockForUpdate()
                    ->findOrFail($session->id);

                $this->ensureEligibleSession($lockedSession, $actor);

                $existingLease = NativeProxyLease::query()
                    ->whereBelongsTo($lockedSession, 'querySession')
                    ->lockForUpdate()
                    ->first();

                if ($existingLease !== null) {
                    throw new NativeProxyCredentialsAlreadyCreatedException($existingLease);
                }

                $password = $this->newPassword();
                $lease = NativeProxyLease::query()->create([
                    'query_session_id' => $lockedSession->id,
                    'query_request_id' => $lockedSession->query_request_id,
                    'user_id' => $lockedSession->user_id,
                    'database_connection_id' => $lockedSession->database_connection_id,
                    'protocol' => $lockedSession->databaseConnection->driver,
                    'access_mode' => $lockedSession->queryRequest->requested_access_mode,
                    'synthetic_username' => $this->newUsername(),
                    'synthetic_password_hash' => Hash::make($password),
                    'protocol_auth_secret' => $password,
                    'credential_creation_idempotency_key' => $idempotencyKey,
                    'credential_version' => 1,
                    'status' => NativeProxyLeaseStatus::Active,
                    'max_concurrent_connections' => 3,
                    'credentials_revealed_at' => now(),
                    'activated_at' => now(),
                    'expires_at' => $lockedSession->expires_at,
                ]);

                $this->auditLogger->log('native_proxy.lease_created', $actor, $lease, [
                    'query_session_id' => $lockedSession->id,
                    'query_request_id' => $lockedSession->query_request_id,
                    'database_connection_id' => $lockedSession->database_connection_id,
                    'access_mode' => $lease->access_mode->value,
                    'credential_version' => $lease->credential_version,
                ]);

                NativeProxyCredentialsCreated::dispatch($lease->id);

                return new NativeProxyCredential($lease, $lease->synthetic_username, $password);
            }, attempts: 3);
        } catch (QueryException $exception) {
            $existingLease = NativeProxyLease::query()
                ->where('query_session_id', $session->id)
                ->first();

            if ($existingLease !== null) {
                throw new NativeProxyCredentialsAlreadyCreatedException($existingLease);
            }

            throw $exception;
        }
    }

    /**
     * @throws ValidationException
     */
    public function rotate(NativeProxyLease $lease, User $actor): NativeProxyCredential
    {
        return DB::transaction(function () use ($lease, $actor): NativeProxyCredential {
            $lockedLease = NativeProxyLease::query()
                ->with(['querySession.queryRequest', 'databaseConnection'])
                ->lockForUpdate()
                ->findOrFail($lease->id);

            $this->ensureEligibleSession($lockedLease->querySession, $actor);

            if ($lockedLease->status !== NativeProxyLeaseStatus::Active) {
                throw ValidationException::withMessages([
                    'lease' => 'Credentials can only be rotated for an active native client session.',
                ]);
            }

            $password = $this->newPassword();
            $this->invalidateConnectionsForCredentialRotation($lockedLease, 'Credentials rotated.');

            $lockedLease->forceFill([
                'synthetic_password_hash' => Hash::make($password),
                'protocol_auth_secret' => $password,
                'credential_version' => $lockedLease->credential_version + 1,
                'credentials_revealed_at' => now(),
            ])->save();

            $this->auditLogger->log('native_proxy.credentials_rotated', $actor, $lockedLease, [
                'query_session_id' => $lockedLease->query_session_id,
                'credential_version' => $lockedLease->credential_version,
            ]);

            NativeProxyLeaseRevoked::dispatch([$lockedLease->id], 'Credentials rotated.');

            return new NativeProxyCredential($lockedLease, $lockedLease->synthetic_username, $password);
        }, attempts: 3);
    }

    /**
     * @throws ValidationException
     */
    public function revoke(NativeProxyLease $lease, User $actor, string $reason): void
    {
        DB::transaction(function () use ($lease, $actor, $reason): void {
            $lockedLease = NativeProxyLease::query()
                ->with('querySession.queryRequest')
                ->lockForUpdate()
                ->findOrFail($lease->id);

            $this->ensureEligibleSession($lockedLease->querySession, $actor);

            if (in_array($lockedLease->status, [NativeProxyLeaseStatus::Revoked, NativeProxyLeaseStatus::Expired], true)) {
                return;
            }

            $this->revokeActiveLeaseAccess($lockedLease, $reason);
            $lockedLease->forceFill([
                'status' => NativeProxyLeaseStatus::Revoked,
                'revoked_at' => now(),
                'revoked_by_id' => $actor->id,
                'revocation_reason' => $reason,
                'synthetic_password_hash' => null,
                'protocol_auth_secret' => null,
            ])->save();

            $this->auditLogger->log('native_proxy.lease_revoked', $actor, $lockedLease, [
                'query_session_id' => $lockedLease->query_session_id,
                'reason' => $reason,
            ]);

            NativeProxyLeaseRevoked::dispatch([$lockedLease->id], $reason);
        }, attempts: 3);
    }

    public function revokeForQuerySession(QuerySession $querySession, ?User $actor, string $reason): int
    {
        return $this->revokeMatchingLeases(
            NativeProxyLease::query()->where('query_session_id', $querySession->id),
            $actor,
            $reason,
        );
    }

    public function revokeForQueryRequest(QueryRequest $queryRequest, ?User $actor, string $reason): int
    {
        return $this->revokeMatchingLeases(
            NativeProxyLease::query()->where('query_request_id', $queryRequest->id),
            $actor,
            $reason,
        );
    }

    /**
     * @param  array<int, int>  $databaseConnectionIds
     */
    public function revokeForDatabaseConnections(array $databaseConnectionIds, ?User $actor, string $reason): int
    {
        if ($databaseConnectionIds === []) {
            return 0;
        }

        return $this->revokeMatchingLeases(
            NativeProxyLease::query()->whereIn('database_connection_id', $databaseConnectionIds),
            $actor,
            $reason,
        );
    }

    /**
     * @param  array<int, int>  $userIds
     */
    public function revokeForUsers(array $userIds, ?User $actor, string $reason): int
    {
        if ($userIds === []) {
            return 0;
        }

        return $this->revokeMatchingLeases(
            NativeProxyLease::query()->whereIn('user_id', $userIds),
            $actor,
            $reason,
        );
    }

    public function revokeAllActive(?User $actor, string $reason): int
    {
        return $this->revokeMatchingLeases(NativeProxyLease::query(), $actor, $reason);
    }

    public function expireDue(): int
    {
        $leaseIds = NativeProxyLease::query()
            ->whereIn('status', [NativeProxyLeaseStatus::PendingCredentials, NativeProxyLeaseStatus::Active])
            ->where('expires_at', '<=', now())
            ->pluck('id');

        $expiredLeaseCount = 0;

        foreach ($leaseIds as $leaseId) {
            $expired = DB::transaction(function () use ($leaseId): bool {
                $lease = NativeProxyLease::query()->lockForUpdate()->find($leaseId);

                if ($lease === null || ! in_array($lease->status, [NativeProxyLeaseStatus::PendingCredentials, NativeProxyLeaseStatus::Active], true) || $lease->expires_at->isFuture()) {
                    return false;
                }

                $this->revokeActiveLeaseAccess($lease, 'Lease expired.');
                $lease->forceFill([
                    'status' => NativeProxyLeaseStatus::Expired,
                    'synthetic_password_hash' => null,
                    'protocol_auth_secret' => null,
                    'revocation_reason' => 'Lease expired.',
                ])->save();

                $this->auditLogger->log('native_proxy.lease_expired', null, $lease, [
                    'query_session_id' => $lease->query_session_id,
                ]);

                NativeProxyLeaseRevoked::dispatch([$lease->id], 'Lease expired.');

                return true;
            }, attempts: 3);

            $expiredLeaseCount += $expired ? 1 : 0;
        }

        return $expiredLeaseCount;
    }

    /**
     * @throws ValidationException
     */
    private function ensureEligibleSession(QuerySession $session, User $actor): void
    {
        $session->loadMissing(['queryRequest', 'databaseConnection']);

        if (! $session->isActive() || $session->queryRequest->request_kind !== QueryRequestKind::QueryAccess || $session->queryRequest->access_transport !== AccessTransport::NativeProxy) {
            throw ValidationException::withMessages([
                'query_session' => 'This is not an active Native Client Access session.',
            ]);
        }

        $queryType = $session->queryRequest->requested_access_mode === AccessMode::Write
            ? QueryType::Write
            : QueryType::Read;

        if (! $session->databaseConnection->is_active || (! $actor->isAdmin() && ! $actor->effectiveNativeProxyPermissionFor($session->databaseConnection, $queryType)['native_proxy_access_mode']->allows($queryType))) {
            throw ValidationException::withMessages([
                'query_session' => 'You no longer have the approved Native Client Access level for this database.',
            ]);
        }
    }

    private function revokeActiveLeaseAccess(NativeProxyLease $lease, string $reason): void
    {
        $now = now();

        $this->expirePendingAuthAttempts($lease, $now);

        $lease->tokens()->whereNull('revoked_at')->update([
            'revoked_at' => $now,
            'revocation_reason' => $reason,
            'updated_at' => $now,
        ]);

        $this->disconnectActiveConnections($lease, $reason, $now);
    }

    private function invalidateConnectionsForCredentialRotation(NativeProxyLease $lease, string $reason): void
    {
        $now = now();

        $this->expirePendingAuthAttempts($lease, $now);
        $this->disconnectActiveConnections($lease, $reason, $now);
    }

    private function expirePendingAuthAttempts(NativeProxyLease $lease, \DateTimeInterface $now): void
    {
        $lease->authAttempts()
            ->where('status', 'pending')
            ->update([
                'status' => 'expired',
                'updated_at' => $now,
            ]);
    }

    private function disconnectActiveConnections(NativeProxyLease $lease, string $reason, \DateTimeInterface $now): void
    {
        $lease->connections()
            ->whereIn('status', [NativeProxyConnectionStatus::Reserved, NativeProxyConnectionStatus::Active])
            ->update([
                'status' => NativeProxyConnectionStatus::Revoked,
                'disconnected_at' => $now,
                'disconnect_reason' => $reason,
                'updated_at' => $now,
            ]);
    }

    /**
     * @param  Builder<NativeProxyLease>  $query
     */
    private function revokeMatchingLeases(Builder $query, ?User $actor, string $reason): int
    {
        $leaseIds = $query
            ->whereIn('status', [NativeProxyLeaseStatus::PendingCredentials, NativeProxyLeaseStatus::Active])
            ->pluck('id')
            ->all();

        if ($leaseIds === []) {
            return 0;
        }

        DB::transaction(function () use ($leaseIds, $actor, $reason): void {
            $now = now();
            NativeProxyToken::query()
                ->whereIn('lease_id', $leaseIds)
                ->whereNull('revoked_at')
                ->update([
                    'revoked_at' => $now,
                    'revocation_reason' => $reason,
                    'updated_at' => $now,
                ]);
            NativeProxyAuthAttempt::query()
                ->whereIn('lease_id', $leaseIds)
                ->where('status', 'pending')
                ->update([
                    'status' => 'expired',
                    'updated_at' => $now,
                ]);
            NativeProxyConnection::query()
                ->whereIn('lease_id', $leaseIds)
                ->whereIn('status', [NativeProxyConnectionStatus::Reserved, NativeProxyConnectionStatus::Active])
                ->update([
                    'status' => NativeProxyConnectionStatus::Revoked,
                    'disconnected_at' => $now,
                    'disconnect_reason' => $reason,
                    'updated_at' => $now,
                ]);
            NativeProxyLease::query()
                ->whereIn('id', $leaseIds)
                ->whereIn('status', [NativeProxyLeaseStatus::PendingCredentials, NativeProxyLeaseStatus::Active])
                ->update([
                    'status' => NativeProxyLeaseStatus::Revoked,
                    'revoked_at' => $now,
                    'revoked_by_id' => $actor?->id,
                    'revocation_reason' => $reason,
                    'synthetic_password_hash' => null,
                    'protocol_auth_secret' => null,
                    'updated_at' => $now,
                ]);

            NativeProxyLeaseRevoked::dispatch($leaseIds, $reason);
        }, attempts: 3);

        return count($leaseIds);
    }

    private function newPassword(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function newUsername(): string
    {
        return $this->applicationSettings->nativeProxyUsernamePrefix().bin2hex(random_bytes(16));
    }
}
