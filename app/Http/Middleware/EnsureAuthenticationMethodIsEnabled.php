<?php

namespace App\Http\Middleware;

use App\Services\ApplicationSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAuthenticationMethodIsEnabled
{
    public function __construct(private readonly ApplicationSettings $settings) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('passkeys/login*') && ! $this->settings->passkeyLoginEnabled()) {
            abort(404);
        }

        if (
            $request->is('forgot-password')
            || $request->is('reset-password')
            || $request->is('reset-password/*')
        ) {
            abort_unless($this->settings->passwordLoginEnabled(), 404);
        }

        return $next($request);
    }
}
