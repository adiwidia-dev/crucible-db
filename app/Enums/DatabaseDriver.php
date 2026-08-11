<?php

namespace App\Enums;

enum DatabaseDriver: string
{
    case MySql = 'mysql';
    case PostgreSql = 'pgsql';

    public function defaultPort(): int
    {
        return match ($this) {
            self::MySql => 3306,
            self::PostgreSql => 5432,
        };
    }
}
