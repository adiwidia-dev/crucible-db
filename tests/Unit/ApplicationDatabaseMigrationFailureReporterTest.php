<?php

namespace Tests\Unit;

use App\Services\ApplicationDatabaseMigrationFailureReporter;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

class ApplicationDatabaseMigrationFailureReporterTest extends TestCase
{
    public function test_it_replaces_sensitive_failures_with_a_correlation_reference(): void
    {
        Log::spy();
        $rawMessage = 'SQLSTATE insert into audit_logs values (secret-password)';

        $failure = app(ApplicationDatabaseMigrationFailureReporter::class)->capture(
            new RuntimeException($rawMessage, 1234),
            'copy',
            '01K4A1B2C3D4E5F6G7H8J9K0MN',
        );

        $this->assertStringContainsString('Reference:', $failure->getMessage());
        $this->assertStringNotContainsString('SQLSTATE', $failure->getMessage());
        $this->assertStringNotContainsString('secret-password', $failure->getMessage());

        Log::shouldHaveReceived('error')->once()->withArgs(
            function (string $message, array $context) use ($rawMessage): bool {
                return $message === 'Application database migration operation failed.'
                    && $context['phase'] === 'copy'
                    && $context['plan_id'] === '01K4A1B2C3D4E5F6G7H8J9K0MN'
                    && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), $rawMessage)
                    && ! array_key_exists('exception', $context);
            },
        );
    }
}
