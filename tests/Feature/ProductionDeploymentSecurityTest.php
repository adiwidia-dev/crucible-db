<?php

namespace Tests\Feature;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Tests\TestCase;

class ProductionDeploymentSecurityTest extends TestCase
{
    public function test_production_origin_is_loopback_only_and_requires_external_https_configuration(): void
    {
        $compose = file_get_contents(base_path('compose.production.yaml'));
        $environment = file_get_contents(base_path('.env.production.example'));
        $gateway = file_get_contents(base_path('.docker/native-proxy.Caddyfile'));

        $this->assertIsString($compose);
        $this->assertIsString($environment);
        $this->assertIsString($gateway);
        $this->assertStringContainsString('${CRUCIBLE_BIND_ADDRESS:-127.0.0.1}:${CRUCIBLE_HTTP_PORT:-8000}:8000', $compose);
        $this->assertStringContainsString('CRUCIBLE_INITIAL_SETUP_TOKEN must contain at least 32 characters', $compose);
        $this->assertStringContainsString('APP_URL=https://', $environment);
        $this->assertStringContainsString('SESSION_SECURE_COOKIE=true', $environment);
        $this->assertStringContainsString('trusted_proxies static private_ranges', $gateway);
        $this->assertStringContainsString('trusted_proxies_strict', $gateway);
    }

    public function test_private_tls_terminator_forwarding_restores_the_public_https_scheme(): void
    {
        config(['trustedproxy.proxies' => ['10.0.0.0/8']]);
        $request = Request::create('http://app:8000/dashboard', server: [
            'REMOTE_ADDR' => '10.20.0.10',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_HOST' => 'crucible.example.com',
            'HTTP_X_FORWARDED_PORT' => '443',
        ]);

        $response = app(TrustProxies::class)->handle(
            $request,
            static fn (Request $request) => response()->json([
                'scheme' => $request->getScheme(),
                'host' => $request->getHost(),
                'port' => $request->getPort(),
            ]),
        );

        $this->assertSame('https', $response->getData(true)['scheme']);
        $this->assertSame('crucible.example.com', $response->getData(true)['host']);
        $this->assertSame(443, $response->getData(true)['port']);
    }
}
