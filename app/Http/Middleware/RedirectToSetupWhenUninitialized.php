<?php

namespace App\Http\Middleware;

use App\Services\ApplicationDatabaseManager;
use App\Services\InitialSetupAccess;
use App\Services\InitialSetupState;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class RedirectToSetupWhenUninitialized
{
    public function __construct(
        private readonly ApplicationDatabaseManager $applicationDatabaseManager,
        private readonly InitialSetupAccess $initialSetupAccess,
        private readonly InitialSetupState $initialSetupState,
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('health') || $request->is('up')) {
            return $next($request);
        }

        $canInitialize = $this->initialSetupState->canInitialize();

        if ($canInitialize && $request->routeIs('setup.access.*')) {
            return $next($request);
        }

        if ($canInitialize && ! $this->initialSetupAccess->isGranted($request)) {
            return redirect()->route('setup.access.create');
        }

        if ($this->applicationDatabaseManager->requiresRestart()) {
            if ($request->routeIs('setup.database.restart', 'application-database-migrations.*')) {
                return $next($request);
            }

            return redirect()->route('setup.database.restart');
        }

        $hasUsersTable = Schema::hasTable('users');
        if ($this->applicationDatabaseManager->requiresSelection() && $canInitialize) {
            if ($request->routeIs('setup.database.create', 'setup.database.store')) {
                return $next($request);
            }

            return redirect()->route('setup.database.create');
        }

        if ($request->is('setup*') || ! $hasUsersTable || ! $canInitialize) {
            return $next($request);
        }

        return redirect()->route('setup.show');
    }
}
