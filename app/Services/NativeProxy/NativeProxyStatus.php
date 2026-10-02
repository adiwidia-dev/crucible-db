<?php

namespace App\Services\NativeProxy;

use App\Enums\NativeProxyConnectionStatus;
use App\Models\NativeProxyConnection;
use App\Models\QuerySession;
use App\Models\User;

class NativeProxyStatus
{
    public function __construct(private readonly ProxyHealth $proxyHealth) {}

    /**
     * @return array{
     *     health: array{status: 'disabled'|'healthy'|'unhealthy'|'version_mismatch', checked_at: string|null, proxy_id: string|null, version: string|null, message: string|null},
     *     connections: int,
     *     instances: int
     * }
     */
    public function for(User $user): array
    {
        $visibleSessionIds = QuerySession::query()
            ->visibleTo($user)
            ->select('id');
        $activeConnections = NativeProxyConnection::query()
            ->whereIn('query_session_id', $visibleSessionIds)
            ->whereIn('status', [NativeProxyConnectionStatus::Reserved, NativeProxyConnectionStatus::Active]);

        return [
            'health' => $this->proxyHealth->latest(),
            'connections' => (clone $activeConnections)->count(),
            'instances' => (clone $activeConnections)->distinct('proxy_instance_id')->count('proxy_instance_id'),
        ];
    }
}
