<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class RedirectToSetupWhenUninitialized
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('setup*') || $request->is('health') || $request->is('up') || ! Schema::hasTable('users') || User::query()->exists()) {
            return $next($request);
        }

        return redirect()->route('setup.show');
    }
}
