<?php

namespace App\Services;

use App\Exceptions\ApplicationDatabaseMigrationOperationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class ApplicationDatabaseMigrationFailureReporter
{
    public function capture(
        Throwable $exception,
        string $phase,
        ?string $planId = null,
    ): ApplicationDatabaseMigrationOperationException {
        if ($exception instanceof ApplicationDatabaseMigrationOperationException) {
            return $exception;
        }

        $reference = (string) Str::ulid();

        Log::error('Application database migration operation failed.', [
            'reference' => $reference,
            'phase' => $phase,
            'plan_id' => $planId,
            'exception_class' => $exception::class,
            'exception_code' => (string) $exception->getCode(),
        ]);

        return new ApplicationDatabaseMigrationOperationException($reference, $phase);
    }
}
