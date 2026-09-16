<?php

namespace App\Http\Middleware;

use App\Services\AuditLogger;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

class CaptureAuditContext
{
    public function handle(Request $request, Closure $next): Response
    {
        Context::addHidden(AuditLogger::ClientIpContextKey, $request->ip());

        return $next($request);
    }
}
