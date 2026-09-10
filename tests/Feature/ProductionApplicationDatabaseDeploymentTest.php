<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class ProductionApplicationDatabaseDeploymentTest extends TestCase
{
    public function test_production_compose_offers_isolated_optional_database_profiles(): void
    {
        $compose = (string) file_get_contents(dirname(__DIR__, 2).'/compose.production.yaml');

        $this->assertStringContainsString('control-postgres:', $compose);
        $this->assertStringContainsString('profiles: ["control-postgres"]', $compose);
        $this->assertStringContainsString('control-mysql:', $compose);
        $this->assertStringContainsString('profiles: ["control-mysql"]', $compose);
        $this->assertStringNotContainsString('"5432:5432"', $compose);
        $this->assertStringNotContainsString('"3306:3306"', $compose);
        $this->assertStringNotContainsString('DB_CONNECTION: sqlite', $compose);
        $this->assertStringContainsString('crucible_storage:/app/storage', $compose);
    }

    public function test_production_entrypoint_waits_for_the_selected_database(): void
    {
        $entrypoint = (string) file_get_contents(dirname(__DIR__, 2).'/.docker/production-entrypoint.sh');

        $this->assertStringContainsString('DATABASE_STARTUP_ATTEMPTS', $entrypoint);
        $this->assertStringContainsString('until php artisan migrate --force --no-interaction', $entrypoint);
        $this->assertStringNotContainsString('php artisan package:discover', $entrypoint);
    }

    public function test_production_app_uses_its_embedded_caddy_gateway(): void
    {
        $compose = (string) file_get_contents(dirname(__DIR__, 2).'/compose.production.yaml');
        $dockerfile = (string) file_get_contents(dirname(__DIR__, 2).'/Dockerfile.production');
        $supervisor = (string) file_get_contents(dirname(__DIR__, 2).'/.docker/production-supervisord.conf');

        $this->assertStringNotContainsString("\n  gateway:\n", $compose);
        $this->assertStringContainsString('${CRUCIBLE_BIND_ADDRESS:-127.0.0.1}:${CRUCIBLE_HTTP_PORT:-8000}:8000', $compose);
        $this->assertStringContainsString('COPY .docker/Caddyfile /etc/caddy/Caddyfile', $dockerfile);
        $this->assertStringContainsString('--caddyfile=/etc/caddy/Caddyfile', $supervisor);
    }

    public function test_production_environment_documents_managed_metadata_and_all_drivers(): void
    {
        $environment = (string) file_get_contents(dirname(__DIR__, 2).'/.env.production.example');

        $this->assertStringContainsString('CRUCIBLE_DATABASE_CONFIG_MODE=managed', $environment);
        $this->assertStringContainsString('CRUCIBLE_DATABASE_CONFIG_FILE=', $environment);
        $this->assertStringContainsString('DB_CONNECTION=sqlite', $environment);
        $this->assertStringContainsString('DB_SSLMODE=prefer', $environment);
        $this->assertStringContainsString('MYSQL_ATTR_SSL_CA=', $environment);
    }
}
