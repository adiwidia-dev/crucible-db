<?php

namespace App\Exceptions;

use RuntimeException;

class ApplicationDatabaseMigrationOperationException extends RuntimeException
{
    public function __construct(
        public readonly string $reference,
        public readonly string $phase,
    ) {
        parent::__construct("Application database migration {$phase} failed. Reference: {$reference}.");
    }
}
