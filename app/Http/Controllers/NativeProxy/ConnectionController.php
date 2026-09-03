<?php

namespace App\Http\Controllers\NativeProxy;

use App\Http\Controllers\Controller;
use App\Models\QuerySession;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class ConnectionController extends Controller
{
    public function index(QuerySession $querySession): JsonResponse
    {
        Gate::authorize('view', $querySession);

        $lease = $querySession->nativeProxyLease;
        $connections = $lease?->connections()
            ->latest('connected_at')
            ->paginate(25);
        $authorizedDevices = $lease?->deviceAuthorizations()
            ->withActiveToken()
            ->latest('consumed_at')
            ->get();

        return response()
            ->json([
                'data' => $connections?->through(fn ($connection): array => [
                    'id' => $connection->id,
                    'protocol' => $connection->protocol->value,
                    'status' => $connection->status->value,
                    'client_application' => $connection->client_application,
                    'connected_at' => $connection->connected_at?->toIso8601String(),
                    'last_activity_at' => $connection->last_activity_at?->toIso8601String(),
                    'statement_count' => $connection->statement_count,
                ])->items() ?? [],
                'authorized_devices' => $authorizedDevices?->map(fn ($authorization): array => [
                    'id' => $authorization->id,
                    'device_label' => $authorization->device_label,
                    'operating_system' => $authorization->operating_system,
                    'architecture' => $authorization->architecture,
                    'authorized_at' => $authorization->consumed_at?->toIso8601String(),
                ])->values()->all() ?? [],
                'meta' => [
                    'current_page' => $connections?->currentPage() ?? 1,
                    'last_page' => $connections?->lastPage() ?? 1,
                    'per_page' => $connections?->perPage() ?? 25,
                    'total' => $connections?->total() ?? 0,
                ],
            ])
            ->header('Cache-Control', 'no-store, private');
    }
}
