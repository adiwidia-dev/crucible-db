<?php

namespace Tests\Feature;

use App\Http\Middleware\CaptureAuditContext;
use App\Models\AuditLog;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Tests\TestCase;

class AuditContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_verified_client_ip_is_captured_for_audit_events_and_queued_work(): void
    {
        config(['trustedproxy.proxies' => ['10.0.0.0/8']]);
        $request = Request::create('http://app:8000/dashboard', server: [
            'REMOTE_ADDR' => '10.20.0.10',
            'HTTP_X_FORWARDED_FOR' => '182.253.55.39',
        ]);

        app(TrustProxies::class)->handle(
            $request,
            fn (Request $request) => app(CaptureAuditContext::class)->handle($request, fn () => response()->noContent()),
        );

        $auditLog = app(AuditLogger::class)->log('audit_context.verified_client_ip');

        $this->assertSame('182.253.55.39', $auditLog->ip_address);
        $this->assertSame('182.253.55.39', Context::getHidden(AuditLogger::ClientIpContextKey));
    }

    public function test_console_audit_events_do_not_record_the_worker_loopback_address(): void
    {
        Context::flush();

        $auditLog = app(AuditLogger::class)->log('audit_context.console_event');

        $this->assertNull($auditLog->ip_address);
        $this->assertSame(1, AuditLog::query()->count());
    }

    protected function tearDown(): void
    {
        Context::flush();

        parent::tearDown();
    }
}
