<?php

namespace App\Services\NativeProxy;

use App\Enums\NativeProxyDeviceAuthorizationStatus;
use App\Enums\NativeProxyLeaseStatus;
use App\Models\NativeProxyDeviceAuthorization;
use App\Models\NativeProxyLease;
use App\Models\NativeProxyToken;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\NativeProxyDeviceAuthorizationChallenge;
use App\Support\NativeProxyDeviceTokenResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeviceAuthorizationWorkflow
{
    private const int DeviceCodeLifetimeSeconds = 300;

    private const int InitialPollingIntervalSeconds = 5;

    private const int MaximumPollingIntervalSeconds = 60;

    private const int MaximumActiveDeviceTokens = 3;

    private const string UserCodeAlphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  array{cli_version:string, operating_system:string, architecture:string, device_label?:string|null}  $metadata
     *
     * @throws ValidationException
     */
    public function begin(NativeProxyLease $lease, array $metadata): NativeProxyDeviceAuthorizationChallenge
    {
        return DB::transaction(function () use ($lease, $metadata): NativeProxyDeviceAuthorizationChallenge {
            $lockedLease = NativeProxyLease::query()->lockForUpdate()->findOrFail($lease->id);
            $this->ensureActiveLease($lockedLease);

            if (NativeProxyDeviceAuthorization::query()
                ->where('lease_id', $lockedLease->id)
                ->where('status', NativeProxyDeviceAuthorizationStatus::Pending)
                ->where('expires_at', '>', now())
                ->count() >= (int) config('native_proxy.max_pending_device_authorizations_per_lease')) {
                throw ValidationException::withMessages([
                    'lease' => 'Too many device authorizations are awaiting approval for this lease.',
                ]);
            }

            $deviceCode = $this->randomToken();
            $userCode = $this->newUserCode();
            $authorization = NativeProxyDeviceAuthorization::query()->create([
                'lease_id' => $lockedLease->id,
                'device_code_hash' => hash('sha256', $deviceCode),
                'user_code_hash' => hash('sha256', $userCode),
                'cli_version' => $metadata['cli_version'],
                'operating_system' => $metadata['operating_system'],
                'architecture' => $metadata['architecture'],
                'device_label' => $metadata['device_label'] ?? null,
                'polling_interval_seconds' => self::InitialPollingIntervalSeconds,
                'status' => NativeProxyDeviceAuthorizationStatus::Pending,
                'expires_at' => now()->addSeconds(self::DeviceCodeLifetimeSeconds),
            ]);

            $this->auditLogger->log('native_proxy.device_authorization_started', null, $authorization, [
                'lease_id' => $lockedLease->id,
                'cli_version' => $authorization->cli_version,
                'operating_system' => $authorization->operating_system,
                'architecture' => $authorization->architecture,
            ]);

            return new NativeProxyDeviceAuthorizationChallenge($authorization, $deviceCode, $userCode);
        }, attempts: 3);
    }

    /**
     * @throws ValidationException
     */
    public function approve(NativeProxyDeviceAuthorization $authorization, User $actor): void
    {
        $this->transitionByOwner($authorization, $actor, NativeProxyDeviceAuthorizationStatus::Approved);
    }

    /**
     * @throws ValidationException
     */
    public function deny(NativeProxyDeviceAuthorization $authorization, User $actor): void
    {
        $this->transitionByOwner($authorization, $actor, NativeProxyDeviceAuthorizationStatus::Denied);
    }

    public function poll(string $deviceCode): NativeProxyDeviceTokenResult
    {
        $deviceCodeHash = hash('sha256', $deviceCode);

        return DB::transaction(function () use ($deviceCodeHash): NativeProxyDeviceTokenResult {
            $authorization = NativeProxyDeviceAuthorization::query()
                ->with('lease.querySession.queryRequest')
                ->where('device_code_hash', $deviceCodeHash)
                ->lockForUpdate()
                ->first();

            if ($authorization === null || ! hash_equals($authorization->device_code_hash, $deviceCodeHash)) {
                return new NativeProxyDeviceTokenResult('invalid_device_code');
            }

            if ($authorization->status === NativeProxyDeviceAuthorizationStatus::Pending && $authorization->last_polled_at?->addSeconds($authorization->polling_interval_seconds)->isFuture()) {
                $nextPollingInterval = min(
                    self::MaximumPollingIntervalSeconds,
                    $authorization->polling_interval_seconds + self::InitialPollingIntervalSeconds,
                );
                $authorization->forceFill([
                    'polling_interval_seconds' => $nextPollingInterval,
                    'last_polled_at' => now(),
                ])->save();

                return new NativeProxyDeviceTokenResult('slow_down', pollingIntervalSeconds: $nextPollingInterval);
            }

            $authorization->forceFill([
                'last_polled_at' => now(),
                'poll_count' => $authorization->poll_count + 1,
            ])->save();

            if ($authorization->expires_at->isPast()) {
                $authorization->forceFill(['status' => NativeProxyDeviceAuthorizationStatus::Expired])->save();

                return new NativeProxyDeviceTokenResult('expired_token');
            }

            if ($authorization->status === NativeProxyDeviceAuthorizationStatus::Pending) {
                return new NativeProxyDeviceTokenResult('authorization_pending', pollingIntervalSeconds: $authorization->polling_interval_seconds);
            }

            if ($authorization->status === NativeProxyDeviceAuthorizationStatus::Denied) {
                return new NativeProxyDeviceTokenResult('access_denied');
            }

            if ($authorization->status !== NativeProxyDeviceAuthorizationStatus::Approved) {
                return new NativeProxyDeviceTokenResult('invalid_device_code');
            }

            $lease = NativeProxyLease::query()
                ->with(['querySession.queryRequest', 'databaseConnection', 'user'])
                ->lockForUpdate()
                ->findOrFail($authorization->lease_id);

            if (! $this->isActiveLease($lease)) {
                return new NativeProxyDeviceTokenResult('expired_lease');
            }

            if ($lease->tokens()->active()->count() >= self::MaximumActiveDeviceTokens) {
                return new NativeProxyDeviceTokenResult('access_denied');
            }

            $token = $this->randomToken();
            NativeProxyToken::query()->create([
                'lease_id' => $lease->id,
                'device_authorization_id' => $authorization->id,
                'token_hash' => hash('sha256', $token),
                'issued_at' => now(),
                'expires_at' => $lease->expires_at,
            ]);
            $authorization->forceFill([
                'status' => NativeProxyDeviceAuthorizationStatus::Consumed,
                'consumed_at' => now(),
            ])->save();

            $this->auditLogger->log('native_proxy.device_authorization_consumed', $lease->user, $authorization, [
                'lease_id' => $lease->id,
            ]);

            return new NativeProxyDeviceTokenResult(
                'issued',
                $token,
                max(0, (int) now()->diffInSeconds($lease->expires_at)),
                deviceAuthorizationId: $authorization->id,
            );
        }, attempts: 3);
    }

    public function findByUserCode(string $userCode): ?NativeProxyDeviceAuthorization
    {
        $userCodeHash = hash('sha256', $userCode);
        $authorization = NativeProxyDeviceAuthorization::query()
            ->with(['lease.querySession.queryRequest', 'lease.databaseConnection'])
            ->where('user_code_hash', $userCodeHash)
            ->first();

        if ($authorization === null || ! hash_equals($authorization->user_code_hash, $userCodeHash)) {
            return null;
        }

        return $authorization;
    }

    public function findByUserCodeFor(User $user, string $userCode): ?NativeProxyDeviceAuthorization
    {
        $authorization = $this->findByUserCode($userCode);

        if ($authorization === null || ! $user->can('manageNativeProxy', $authorization->lease->querySession)) {
            return null;
        }

        return $authorization;
    }

    /**
     * @throws ValidationException
     */
    private function transitionByOwner(NativeProxyDeviceAuthorization $authorization, User $actor, NativeProxyDeviceAuthorizationStatus $targetStatus): void
    {
        DB::transaction(function () use ($authorization, $actor, $targetStatus): void {
            $lockedAuthorization = NativeProxyDeviceAuthorization::query()
                ->with('lease.querySession.queryRequest')
                ->lockForUpdate()
                ->findOrFail($authorization->id);
            $lease = $lockedAuthorization->lease;

            $this->ensureActiveLease($lease);

            if ($actor->isDisabled() || ($lease->user_id !== $actor->id && ! $actor->isAdmin())) {
                throw ValidationException::withMessages([
                    'device_authorization' => 'Only the session owner can approve this device.',
                ]);
            }

            if ($lockedAuthorization->status !== NativeProxyDeviceAuthorizationStatus::Pending || $lockedAuthorization->expires_at->isPast()) {
                throw ValidationException::withMessages([
                    'device_authorization' => 'This device authorization is no longer pending.',
                ]);
            }

            $attributes = $targetStatus === NativeProxyDeviceAuthorizationStatus::Approved
                ? ['status' => $targetStatus, 'approved_at' => now(), 'authorized_by_id' => $actor->id]
                : ['status' => $targetStatus, 'denied_at' => now(), 'authorized_by_id' => $actor->id];
            $lockedAuthorization->forceFill($attributes)->save();

            $this->auditLogger->log(
                $targetStatus === NativeProxyDeviceAuthorizationStatus::Approved
                    ? 'native_proxy.device_authorization_approved'
                    : 'native_proxy.device_authorization_denied',
                $actor,
                $lockedAuthorization,
                ['lease_id' => $lease->id],
            );
        }, attempts: 3);
    }

    /**
     * @throws ValidationException
     */
    private function ensureActiveLease(NativeProxyLease $lease): void
    {
        $lease->loadMissing('querySession.queryRequest', 'databaseConnection', 'user');

        if (! $this->isActiveLease($lease)) {
            throw ValidationException::withMessages([
                'lease' => 'This Native Client Access lease is no longer active.',
            ]);
        }
    }

    private function isActiveLease(NativeProxyLease $lease): bool
    {
        return $lease->status === NativeProxyLeaseStatus::Active
            && $lease->expires_at->isFuture()
            && $lease->querySession->isActive()
            && $lease->databaseConnection->is_active
            && ! $lease->user->isDisabled();
    }

    private function randomToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function newUserCode(): string
    {
        $characters = [];

        for ($index = 0; $index < 8; $index++) {
            $characters[] = self::UserCodeAlphabet[random_int(0, strlen(self::UserCodeAlphabet) - 1)];
        }

        return implode('', array_slice($characters, 0, 4)).'-'.implode('', array_slice($characters, 4));
    }
}
