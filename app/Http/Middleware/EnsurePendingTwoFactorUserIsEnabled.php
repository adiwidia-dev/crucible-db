<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePendingTwoFactorUserIsEnabled
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('two-factor-challenge')) {
            return $next($request);
        }

        $challengedUserId = $request->session()->get('login.id');

        if ($challengedUserId === null) {
            return $next($request);
        }

        $challengedUser = User::query()->find($challengedUserId);

        if ($challengedUser instanceof User && ! $challengedUser->isDisabled()) {
            return $next($request);
        }

        $request->session()->forget(['login.id', 'login.remember']);

        return redirect()->route('login')->withErrors([
            'email' => 'This account is disabled.',
        ]);
    }
}
