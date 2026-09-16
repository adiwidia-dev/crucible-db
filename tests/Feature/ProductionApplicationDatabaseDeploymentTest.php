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
        $this->assertStringContainsString('${CRUCIBLE_IMAGE:?CRUCIBLE_IMAGE must be set to an immutable application release image}', $compose);
        $this->assertStringContainsString('${CRUCIBLE_NATIVE_IMAGE:?CRUCIBLE_NATIVE_IMAGE must be set to the matching native proxy release image}', $compose);
        $this->assertStringNotContainsString('hephaestus/crucible-db:latest', $compose);
        $this->assertStringNotContainsString('hephaestus/crucible-db:0.1.0', $compose);
    }

    public function test_production_entrypoint_waits_for_the_selected_database(): void
    {
        $entrypoint = (string) file_get_contents(dirname(__DIR__, 2).'/.docker/production-entrypoint.sh');

        $this->assertStringContainsString('DATABASE_STARTUP_ATTEMPTS', $entrypoint);
        $this->assertStringContainsString('until php artisan migrate --force --no-interaction', $entrypoint);
        $this->assertStringNotContainsString('php artisan package:discover', $entrypoint);
    }

    public function test_development_entrypoint_creates_the_configured_sqlite_database_before_composer_runs(): void
    {
        $entrypoint = (string) file_get_contents(dirname(__DIR__, 2).'/.docker/entrypoint.sh');

        $databaseSetupPosition = strpos($entrypoint, 'sqlite_database="${DB_DATABASE:-/app/database/database.sqlite}"');
        $composerInstallPosition = strpos($entrypoint, 'composer install --no-interaction');

        $this->assertIsInt($databaseSetupPosition);
        $this->assertIsInt($composerInstallPosition);
        $this->assertStringContainsString('mkdir -p "$(dirname "$sqlite_database")"', $entrypoint);
        $this->assertStringContainsString('touch "$sqlite_database"', $entrypoint);
        $this->assertLessThan($composerInstallPosition, $databaseSetupPosition);
    }

    public function test_native_integration_workflow_migrates_before_starting_the_application(): void
    {
        $workflow = (string) file_get_contents(dirname(__DIR__, 2).'/.github/workflows/native.yml');

        $migrationPosition = strpos($workflow, 'docker compose run --rm app php artisan migrate:fresh');
        $applicationStartPosition = strpos($workflow, 'docker compose up -d app native-proxy');

        $this->assertIsInt($migrationPosition);
        $this->assertIsInt($applicationStartPosition);
        $this->assertLessThan($applicationStartPosition, $migrationPosition);
        $this->assertStringNotContainsString('docker compose restart app', $workflow);
    }

    public function test_release_workflow_publishes_versioned_production_images_after_the_test_gates(): void
    {
        $workflow = (string) file_get_contents(dirname(__DIR__, 2).'/.github/workflows/release-native.yml');
        $releaseConfiguration = (string) file_get_contents(dirname(__DIR__, 2).'/native/.goreleaser.yaml');

        $this->assertStringContainsString('name: release cli and production images', $workflow);
        $this->assertStringContainsString("tags: ['v*']", $workflow);
        $this->assertStringContainsString('workflow_dispatch:', $workflow);
        $this->assertStringContainsString('release_components:', $workflow);
        $this->assertStringContainsString('- attestation', $workflow);
        $this->assertStringContainsString('needs: release-preflight', $workflow);
        $this->assertStringContainsString('needs: [release-preflight, application-gate, native-gate]', $workflow);
        $this->assertStringContainsString('DOCKERHUB_USERNAME: ${{ vars.DOCKERHUB_USERNAME }}', $workflow);
        $this->assertStringContainsString('DOCKERHUB_TOKEN: ${{ secrets.DOCKERHUB_TOKEN }}', $workflow);
        $this->assertStringContainsString('HOMEBREW_TAP_TOKEN: ${{ secrets.HOMEBREW_TAP_TOKEN }}', $workflow);
        $this->assertStringContainsString('images: hephaestus/crucible-db', $workflow);
        $this->assertStringContainsString('images: hephaestus/crucible-db-native', $workflow);
        $this->assertSame(2, substr_count($workflow, 'type=raw,value=${{ needs.release-preflight.outputs.version }}'));
        $this->assertStringContainsString('^v[0-9]+\.[0-9]+\.[0-9]+$', $workflow);
        $this->assertStringContainsString('release_sha="$(git rev-parse "refs/tags/$release_tag^{commit}")"', $workflow);
        $this->assertStringContainsString('git show "$release_tag:package.json"', $workflow);
        $this->assertStringContainsString('git show "$release_tag:mkdocs.yml"', $workflow);
        $this->assertStringContainsString('git cat-file -e "$release_tag:$release_notes"', $workflow);
        $this->assertStringContainsString('git show "$release_tag:CHANGELOG.md"', $workflow);
        $this->assertSame(3, substr_count($workflow, 'ref: ${{ needs.release-preflight.outputs.release_tag }}'));
        $this->assertStringContainsString('anchore/sbom-action/download-syft@3ad7283483fc7af8ff2b4ea19663c2d5ca935e26', $workflow);
        $this->assertStringContainsString('syft-version: v1.51.1', $workflow);
        $this->assertStringContainsString('goreleaser/goreleaser-action@f06c13b6b1a9625abc9e6e439d9c05a8f2190e94', $workflow);
        $this->assertStringContainsString('actions/attest-build-provenance@4d101475d8b20a2381f78447822ac1eab6504dd8', $workflow);
        $this->assertStringContainsString('name: attest published native cli', $workflow);
        $this->assertStringContainsString('gh release download "$RELEASE_TAG"', $workflow);
        $this->assertStringNotContainsString('e435f85e2a9c3a04eacd02cbaed8e1bc67075256', $workflow);
        $this->assertStringNotContainsString('977bb2f4a525b5be3a70f4a3e7a6ec5aa8a14a6c', $workflow);
        $this->assertStringContainsString('file: Dockerfile.production', $workflow);
        $this->assertStringContainsString('file: Dockerfile.native', $workflow);
        $this->assertSame(2, substr_count($workflow, 'platforms: linux/amd64,linux/arm64'));
        $this->assertSame(2, substr_count($workflow, 'provenance: mode=max'));
        $this->assertSame(2, substr_count($workflow, 'sbom: true'));
        $this->assertStringContainsString('docker buildx imagetools inspect "hephaestus/crucible-db@$APPLICATION_DIGEST"', $workflow);
        $this->assertStringContainsString('docker buildx imagetools inspect "hephaestus/crucible-db-native@$NATIVE_DIGEST"', $workflow);
        $this->assertStringContainsString('token: "{{ .Env.HOMEBREW_TAP_TOKEN }}"', $releaseConfiguration);
    }

    public function test_dependabot_targets_the_integration_branch_for_every_ecosystem(): void
    {
        $configuration = (string) file_get_contents(dirname(__DIR__, 2).'/.github/dependabot.yml');

        $this->assertSame(5, substr_count($configuration, 'target-branch: "develop"'));
        $this->assertSame(5, substr_count($configuration, 'package-ecosystem:'));
    }

    public function test_development_postgresql_target_uses_the_version_aware_data_root(): void
    {
        $compose = (string) file_get_contents(dirname(__DIR__, 2).'/compose.yaml');

        $this->assertStringContainsString('target_postgres_data:/var/lib/postgresql', $compose);
        $this->assertStringNotContainsString('target_postgres_data:/var/lib/postgresql/data', $compose);
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
        $this->assertMatchesRegularExpression('/^CRUCIBLE_IMAGE=$/m', $environment);
        $this->assertMatchesRegularExpression('/^CRUCIBLE_NATIVE_IMAGE=$/m', $environment);
        $this->assertStringContainsString('pin both images to the same release version or exact digests', $environment);
    }
}
