<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\ApplicationDatabaseManager;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class RedirectToSetupWhenUninitialized
{
    public function __construct(private readonly ApplicationDatabaseManager $applicationDatabaseManager) {}

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

        if ($this->applicationDatabaseManager->requiresRestart()) {
            if ($request->routeIs('setup.database.restart')) {
                return $next($request);
            }

            return redirect()->route('setup.database.restart');
        }

        $hasUsersTable = Schema::hasTable('users');
        $hasUsers = $hasUsersTable && User::query()->exists();

        if ($this->applicationDatabaseManager->requiresSelection() && ! $hasUsers) {
            if ($request->routeIs('setup.database.create', 'setup.database.store')) {
                return $next($request);
            }

            return redirect()->route('setup.database.create');
        }

        if ($request->is('setup*') || ! $hasUsersTable || $hasUsers) {
            return $next($request);
        }

        return redirect()->route('setup.show');
    }
}
