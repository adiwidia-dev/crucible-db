<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;

class AuditLogger
{
    public const string ClientIpContextKey = 'audit.client_ip';

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function log(string $action, ?User $actor = null, ?Model $auditable = null, array $metadata = [], ?Request $request = null): AuditLog
    {
        if (Context::hasHidden(self::ClientIpContextKey)) {
            return $this->create(
                $action,
                $actor,
                $auditable,
                $metadata,
                $this->clientIpAddress(),
                app()->runningInConsole() ? null : ($request ??= request())->userAgent(),
            );
        }

        if (app()->runningInConsole()) {
            return $this->create($action, $actor, $auditable, $metadata, null, null);
        }

        $request ??= request();

        return $this->create($action, $actor, $auditable, $metadata, $this->clientIpAddress($request), $request->userAgent());
    }

    /**
     * Record an audit event with the verified browser IP persisted for background work.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function logWithClientIp(string $action, ?User $actor = null, ?Model $auditable = null, array $metadata = [], ?string $clientIpAddress = null): AuditLog
    {
        return $this->create($action, $actor, $auditable, $metadata, $clientIpAddress, null);
    }

    public function clientIpAddress(?Request $request = null): ?string
    {
        if (Context::hasHidden(self::ClientIpContextKey)) {
            return $this->normalizeIpAddress(Context::getHidden(self::ClientIpContextKey));
        }

        if (app()->runningInConsole()) {
            return null;
        }

        return $this->normalizeIpAddress(($request ?? request())->ip());
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function create(string $action, ?User $actor, ?Model $auditable, array $metadata, ?string $ipAddress, ?string $userAgent): AuditLog
    {

        return AuditLog::query()->create([
            'actor_id' => $actor?->id,
            'action' => $action,
            'auditable_type' => $auditable?->getMorphClass(),
            'auditable_id' => $auditable?->getKey(),
            'ip_address' => $this->normalizeIpAddress($ipAddress),
            'user_agent' => $userAgent,
            'metadata' => $metadata,
        ]);
    }

    private function normalizeIpAddress(mixed $ipAddress): ?string
    {
        if (! is_string($ipAddress) || filter_var($ipAddress, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        return $ipAddress;
    }
}
