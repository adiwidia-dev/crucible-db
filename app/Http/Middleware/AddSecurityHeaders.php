<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AddSecurityHeaders
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'accelerometer=(), camera=(), gyroscope=(), magnetometer=(), microphone=(), geolocation=(), payment=(), usb=()');
        $response->headers->set('Content-Security-Policy', (string) config('security.content_security_policy'));

        $contentSecurityPolicy = config('security.content_security_policy_report_only');

        if (is_string($contentSecurityPolicy) && $contentSecurityPolicy !== '') {
            $response->headers->set('Content-Security-Policy-Report-Only', $contentSecurityPolicy);
        }

        return $response;
    }
}
