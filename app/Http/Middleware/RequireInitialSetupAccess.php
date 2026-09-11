<?php

namespace App\Http\Middleware;

use App\Services\InitialSetupAccess;
use App\Services\InitialSetupState;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireInitialSetupAccess
{
    public function __construct(
        private readonly InitialSetupAccess $access,
        private readonly InitialSetupState $state,
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->state->canInitialize(), 404);

        if (! $this->access->isGranted($request)) {
            return redirect()->route('setup.access.create');
        }

        return $next($request);
    }
}
