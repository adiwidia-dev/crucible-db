<?php

namespace App\Services\NativeProxy;

use App\Enums\AccessMode;
use App\Enums\AccessTransport;
use App\Enums\NativeProxyConnectionStatus;
use App\Enums\NativeProxyDeviceAuthorizationStatus;
use App\Enums\NativeProxyLeaseStatus;
use App\Enums\QueryRequestKind;
use App\Enums\QueryType;
use App\Models\NativeProxyAuthAttempt;
use App\Models\NativeProxyConnection;
use App\Models\NativeProxyLease;
use App\Models\NativeProxyToken;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class ConnectionAdmission
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @throws ValidationException
     */
    public function authorizeTunnel(TunnelAuthorizationData $data): AuthorizedTunnel
    {
        return DB::transaction(function () use ($data): AuthorizedTunnel {
            $token = NativeProxyToken::query()
                ->with(['lease.querySession.queryRequest', 'lease.querySession.databaseConnection', 'lease.querySession.user', 'deviceAuthorization'])
                ->where('token_hash', $data->bearerTokenHash)
                ->lockForUpdate()
                ->first();

            if ($token === null || ! hash_equals($token->token_hash, $data->bearerTokenHash)) {
                $this->deny();
            }

            $lease = $token->lease;
            $this->ensureActiveLease($lease, $token, $data);

            $existingAttempt = NativeProxyAuthAttempt::query()
                ->where('proxy_connection_id', $data->proxyConnectionId)
                ->lockForUpdate()
                ->first();

            if ($existingAttempt !== null) {
                if ($existingAttempt->lease_id !== $lease->id || $existingAttempt->token_id !== $token->id || ! $existingAttempt->expires_at->isFuture()) {
                    $this->deny();
                }

                return new AuthorizedTunnel(
                    $existingAttempt->id,
                    $lease->id,
                    $existingAttempt->proxy_connection_id,
                    $existingAttempt->protocol->nativeProxyProtocol(),
                    $existingAttempt->credential_version,
                    $lease->protocol_auth_secret,
                );
            }

            $attempt = NativeProxyAuthAttempt::query()->create([
                'lease_id' => $lease->id,
                'token_id' => $token->id,
                'device_authorization_id' => $token->device_authorization_id,
                'proxy_instance_id' => $data->proxyInstanceId,
                'protocol' => $data->protocol,
                'credential_version' => $lease->credential_version,
                'proxy_connection_id' => $data->proxyConnectionId,
                'status' => 'pending',
                'expires_at' => now()->addSeconds(15),
            ]);
            $token->forceFill(['last_used_at' => now()])->save();
            $lease->forceFill(['last_used_at' => now()])->save();
            $this->auditLogger->log('native_proxy.tunnel_authorized', null, $attempt, [
                'lease_id' => $lease->id,
                'protocol' => $data->protocol->value,
                'proxy_instance_id' => $data->proxyInstanceId,
            ]);

            return new AuthorizedTunnel($attempt->id, $lease->id, $attempt->proxy_connection_id, $data->protocol->nativeProxyProtocol(), $attempt->credential_version, $lease->protocol_auth_secret);
        }, attempts: 3);
    }

    /**
     * @throws ValidationException
     */
    public function authorizeConnection(ConsumeAuthAttemptData $data): AdmittedConnection
    {
        return DB::transaction(function () use ($data): AdmittedConnection {
            $attempt = NativeProxyAuthAttempt::query()
                ->with(['lease.querySession.queryRequest', 'lease.querySession.databaseConnection', 'lease.querySession.user', 'deviceAuthorization'])
                ->lockForUpdate()
                ->find($data->authAttemptId);

            if ($attempt === null || $attempt->status !== 'pending' || ! $attempt->expires_at->isFuture() || $attempt->proxy_instance_id !== $data->proxyInstanceId || $attempt->protocol !== $data->protocol) {
                $this->deny();
            }

            $lease = NativeProxyLease::query()->lockForUpdate()->findOrFail($attempt->lease_id);
            $token = NativeProxyToken::query()->lockForUpdate()->findOrFail($attempt->token_id);
            $this->ensureActiveLease($lease, $token, new TunnelAuthorizationData(
                $lease->id,
                $token->device_authorization_id,
                $attempt->proxy_connection_id,
                $data->proxyInstanceId,
                $data->protocol,
                $token->token_hash,
            ));

            if (! hash_equals($lease->synthetic_username, $data->syntheticUsername) || $lease->synthetic_password_hash === null || ! Hash::check($data->syntheticPassword, $lease->synthetic_password_hash)) {
                $this->deny();
            }

            $admissionLock = DB::table('native_proxy_admission_locks')
                ->where('id', 1)
                ->lockForUpdate()
                ->first();
            if ($admissionLock === null) {
                $this->deny();
            }

            $this->failExpiredReservations();

            $leaseConnectionCount = NativeProxyConnection::query()
                ->where('lease_id', $lease->id)
                ->whereIn('status', [NativeProxyConnectionStatus::Reserved, NativeProxyConnectionStatus::Active])
                ->count();
            $userConnectionCount = NativeProxyConnection::query()
                ->where('user_id', $lease->user_id)
                ->whereIn('status', [NativeProxyConnectionStatus::Reserved, NativeProxyConnectionStatus::Active])
                ->count();
            $globalConnectionCount = NativeProxyConnection::query()
                ->whereIn('status', [NativeProxyConnectionStatus::Reserved, NativeProxyConnectionStatus::Active])
                ->count();
            if (
                $leaseConnectionCount >= $lease->max_concurrent_connections
                || $userConnectionCount >= (int) config('native_proxy.max_connections_per_user')
                || $globalConnectionCount >= (int) config('native_proxy.max_connections')
            ) {
                $this->deny();
            }

            $session = $lease->querySession;
            $connection = $session->databaseConnection;
            $nativeConnection = NativeProxyConnection::query()->create([
                'proxy_connection_id' => $attempt->proxy_connection_id,
                'lease_id' => $lease->id,
                'query_session_id' => $session->id,
                'query_request_id' => $session->query_request_id,
                'user_id' => $session->user_id,
                'database_connection_id' => $connection->id,
                'protocol' => $data->protocol,
                'proxy_instance_id' => $data->proxyInstanceId,
                'cli_version' => $attempt->deviceAuthorization->cli_version,
                'operating_system' => $attempt->deviceAuthorization->operating_system,
                'architecture' => $attempt->deviceAuthorization->architecture,
                'upstream_tls_mode' => $connection->tls_mode,
                'upstream_tls_verified' => $connection->tls_mode->verifiesServerCertificate(),
                'status' => NativeProxyConnectionStatus::Reserved,
                'reservation_expires_at' => now()->addSeconds((int) config('native_proxy.reservation_seconds')),
                'connected_at' => now(),
                'last_activity_at' => now(),
            ]);
            $attempt->forceFill(['status' => 'consumed', 'consumed_at' => now()])->save();
            $this->auditLogger->log('native_proxy.connection_reserved', null, $nativeConnection, [
                'lease_id' => $lease->id,
                'protocol' => $data->protocol->value,
            ]);

            return new AdmittedConnection(
                $nativeConnection->id,
                $lease->id,
                (string) $lease->user_id,
                $data->protocol->nativeProxyProtocol(),
                $attempt->credential_version,
                $lease->access_mode === AccessMode::Read,
            );
        }, attempts: 3);
    }

    /** @param array{client_application:string|null, client_version:string|null} $metadata */
    public function markAuthenticated(NativeProxyConnection $connection, array $metadata): void
    {
        DB::transaction(function () use ($connection, $metadata): void {
            $lockedConnection = NativeProxyConnection::query()->lockForUpdate()->findOrFail($connection->id);
            if ($lockedConnection->status !== NativeProxyConnectionStatus::Reserved || $lockedConnection->reservation_expires_at?->isPast()) {
                $this->deny();
            }

            $lockedConnection->forceFill([
                'status' => NativeProxyConnectionStatus::Active,
                'authenticated_at' => now(),
                'last_activity_at' => now(),
                'reservation_expires_at' => null,
                'client_application' => $metadata['client_application'],
                'client_version' => $metadata['client_version'],
            ])->save();
        }, attempts: 3);
    }

    /**
     * @throws ValidationException
     */
    public function upstreamMaterial(NativeProxyConnection $connection): NativeProxyUpstreamMaterial
    {
        return DB::transaction(function () use ($connection): NativeProxyUpstreamMaterial {
            $lockedConnection = NativeProxyConnection::query()
                ->with(['lease.querySession.queryRequest', 'lease.querySession.databaseConnection', 'lease.querySession.user', 'lease.tokens.deviceAuthorization'])
                ->lockForUpdate()
                ->findOrFail($connection->id);

            if (! in_array($lockedConnection->status, [NativeProxyConnectionStatus::Reserved, NativeProxyConnectionStatus::Active], true)) {
                $this->deny();
            }

            $lease = $lockedConnection->lease;
            $token = $lease->tokens->first(static fn (NativeProxyToken $token): bool => $token->revoked_at === null && $token->expires_at->isFuture());

            if ($token === null) {
                $this->deny();
            }

            try {
                $this->ensureActiveLease($lease, $token, new TunnelAuthorizationData(
                    $lease->id,
                    $token->device_authorization_id,
                    $lockedConnection->proxy_connection_id,
                    $lockedConnection->proxy_instance_id,
                    $lockedConnection->protocol,
                    $token->token_hash,
                ));
            } catch (ValidationException) {
                $this->deny();
            }

            $lockedConnection->forceFill(['last_activity_at' => now()])->save();

            $upstream = $lockedConnection->databaseConnection;

            return new NativeProxyUpstreamMaterial(
                $upstream->host,
                $upstream->port,
                $upstream->database,
                $upstream->username,
                $upstream->password,
                $upstream->tls_mode->value,
                $upstream->tls_ca_certificate,
                $upstream->tls_client_certificate,
                $upstream->tls_client_key,
            );
        }, attempts: 3);
    }

    public function recordTraffic(NativeProxyConnection $connection, int $bytesReceived, int $bytesSent): void
    {
        DB::transaction(function () use ($connection, $bytesReceived, $bytesSent): void {
            $lockedConnection = NativeProxyConnection::query()->lockForUpdate()->findOrFail($connection->id);

            if (! in_array($lockedConnection->status, [NativeProxyConnectionStatus::Reserved, NativeProxyConnectionStatus::Active], true)) {
                return;
            }

            $lockedConnection->increment('bytes_received', $bytesReceived);
            $lockedConnection->increment('bytes_sent', $bytesSent, ['last_activity_at' => now()]);
        }, attempts: 3);
    }

    public function heartbeat(NativeProxyConnection $connection): ConnectionHeartbeatDecision
    {
        return DB::transaction(function () use ($connection): ConnectionHeartbeatDecision {
            $lockedConnection = NativeProxyConnection::query()
                ->with(['lease.querySession.queryRequest', 'lease.querySession.databaseConnection', 'lease.querySession.user', 'lease.tokens.deviceAuthorization'])
                ->lockForUpdate()
                ->findOrFail($connection->id);

            if ($lockedConnection->status !== NativeProxyConnectionStatus::Active) {
                return new ConnectionHeartbeatDecision(false, $lockedConnection->status->value);
            }

            $lease = $lockedConnection->lease;
            $token = $lease->tokens->first(static fn (NativeProxyToken $token): bool => $token->revoked_at === null && $token->expires_at->isFuture());
            if ($token === null) {
                $this->closeLocked($lockedConnection, 'Lease access was revoked.');

                return new ConnectionHeartbeatDecision(false, 'revoked');
            }

            try {
                $this->ensureActiveLease($lease, $token, new TunnelAuthorizationData(
                    $lease->id,
                    $token->device_authorization_id,
                    $lockedConnection->proxy_connection_id,
                    $lockedConnection->proxy_instance_id,
                    $lockedConnection->protocol,
                    $token->token_hash,
                ));
            } catch (ValidationException) {
                $this->closeLocked($lockedConnection, 'Lease access is no longer allowed.');

                return new ConnectionHeartbeatDecision(false, 'revoked');
            }

            $lockedConnection->forceFill(['last_activity_at' => now()])->save();

            return new ConnectionHeartbeatDecision(true, 'continue');
        }, attempts: 3);
    }

    public function heartbeatLease(string $leaseId, string $deviceAuthorizationId, string $bearerTokenHash): ConnectionHeartbeatDecision
    {
        $token = NativeProxyToken::query()
            ->with(['lease.querySession.queryRequest', 'lease.querySession.databaseConnection', 'lease.querySession.user', 'deviceAuthorization'])
            ->where('token_hash', $bearerTokenHash)
            ->first();

        if ($token === null || ! hash_equals($token->token_hash, $bearerTokenHash)) {
            return new ConnectionHeartbeatDecision(false, 'revoked');
        }

        try {
            $this->ensureActiveLease($token->lease, $token, new TunnelAuthorizationData(
                $leaseId,
                $deviceAuthorizationId,
                'lease-heartbeat',
                'lease-heartbeat',
                $token->lease->protocol,
                $bearerTokenHash,
            ));
        } catch (ValidationException) {
            return new ConnectionHeartbeatDecision(false, 'revoked');
        }

        return new ConnectionHeartbeatDecision(true, 'continue');
    }

    public function close(NativeProxyConnection $connection, string $reason): void
    {
        DB::transaction(function () use ($connection, $reason): void {
            $this->closeLocked(NativeProxyConnection::query()->lockForUpdate()->findOrFail($connection->id), $reason);
        }, attempts: 3);
    }

    private function closeLocked(NativeProxyConnection $connection, string $reason): void
    {
        if (in_array($connection->status, [NativeProxyConnectionStatus::Closed, NativeProxyConnectionStatus::Revoked, NativeProxyConnectionStatus::Failed], true)) {
            return;
        }

        $connection->forceFill([
            'status' => NativeProxyConnectionStatus::Closed,
            'disconnected_at' => now(),
            'disconnect_reason' => $reason,
        ])->save();

        $this->auditLogger->log('native_proxy.connection_closed', null, $connection, [
            'lease_id' => $connection->lease_id,
            'reason' => str($reason)->limit(128, '')->toString(),
        ]);
    }

    private function failExpiredReservations(): void
    {
        NativeProxyConnection::query()
            ->where('status', NativeProxyConnectionStatus::Reserved)
            ->where('reservation_expires_at', '<=', now())
            ->update([
                'status' => NativeProxyConnectionStatus::Failed,
                'reservation_expires_at' => null,
                'disconnected_at' => now(),
                'disconnect_reason' => 'Native proxy connection reservation expired.',
                'updated_at' => now(),
            ]);
    }

    /**
     * @throws ValidationException
     */
    public function assertOwnedByProxy(NativeProxyConnection $connection, string $proxyInstanceId): void
    {
        if (! is_string($connection->proxy_instance_id) || ! hash_equals($connection->proxy_instance_id, $proxyInstanceId)) {
            $this->deny();
        }
    }

    private function deny(): never
    {
        throw ValidationException::withMessages(['tunnel' => 'Native tunnel authorization was denied.']);
    }

    private function ensureActiveLease(NativeProxyLease $lease, NativeProxyToken $token, TunnelAuthorizationData $data): void
    {
        $session = $lease->querySession;
        $deviceAuthorization = $token->deviceAuthorization;
        $queryRequest = $session->queryRequest;
        $connection = $session->databaseConnection;
        $user = $session->user;
        $queryType = $lease->access_mode === AccessMode::Write ? QueryType::Write : QueryType::Read;

        if (
            $lease->id !== $data->leaseId
            || $lease->protocol !== $data->protocol
            || $lease->status !== NativeProxyLeaseStatus::Active
            || $lease->protocol_auth_secret === null
            || ! $lease->expires_at->isFuture()
            || $token->revoked_at !== null
            || ! $token->expires_at->isFuture()
            || $token->device_authorization_id !== $data->deviceAuthorizationId
            || $deviceAuthorization->lease_id !== $lease->id
            || $deviceAuthorization->status !== NativeProxyDeviceAuthorizationStatus::Consumed
            || ! $session->isActive()
            || $queryRequest->request_kind !== QueryRequestKind::QueryAccess
            || $queryRequest->access_transport !== AccessTransport::NativeProxy
            || ! $connection->is_active
            || $user->isDisabled()
            || (! $user->isAdmin() && ! $user->effectiveNativeProxyPermissionFor($connection, $queryType)['native_proxy_access_mode']->allows($queryType))
        ) {
            $this->deny();
        }
    }
}
