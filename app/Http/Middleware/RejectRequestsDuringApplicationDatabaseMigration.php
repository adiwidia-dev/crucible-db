<?php

namespace App\Http\Middleware;

use App\Services\ApplicationDatabaseMigrationFence;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RejectRequestsDuringApplicationDatabaseMigration
{
    public function __construct(private readonly ApplicationDatabaseMigrationFence $fence) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->fence->isActive()) {
            return $next($request);
        }

        if ($request->routeIs('application-database-migrations.*')) {
            return $next($request);
        }

        $payload = [
            'message' => 'Crucible is temporarily read-only while the application database is being migrated.',
            'retry_after' => 30,
        ];

        if ($request->expectsJson() || $request->is('internal/native-proxy/*')) {
            return response()->json($payload, Response::HTTP_SERVICE_UNAVAILABLE)
                ->header('Retry-After', '30')
                ->header('Cache-Control', 'no-store');
        }

        return response($payload['message'], Response::HTTP_SERVICE_UNAVAILABLE)
            ->header('Retry-After', '30')
            ->header('Cache-Control', 'no-store');
    }
}
