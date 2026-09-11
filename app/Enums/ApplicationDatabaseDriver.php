<?php

namespace App\Enums;

enum ApplicationDatabaseDriver: string
{
    case Sqlite = 'sqlite';
    case MySql = 'mysql';
    case PostgreSql = 'pgsql';

    public function label(): string
    {
        return match ($this) {
            self::Sqlite => 'SQLite',
            self::MySql => 'MySQL',
            self::PostgreSql => 'PostgreSQL',
        };
    }

    public function defaultPort(): ?int
    {
        return match ($this) {
            self::Sqlite => null,
            self::MySql => 3306,
            self::PostgreSql => 5432,
        };
    }

    public function isNetworkDatabase(): bool
    {
        return $this !== self::Sqlite;
    }
}
