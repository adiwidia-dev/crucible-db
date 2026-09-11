<?php

namespace App\Enums;

use InvalidArgumentException;

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

    public function nativeProxyProtocol(): string
    {
        return match ($this) {
            self::MySql => 'mysql',
            self::PostgreSql => 'postgresql',
        };
    }

    public static function fromNativeProxyProtocol(string $protocol): self
    {
        return match ($protocol) {
            'mysql' => self::MySql,
            'postgresql' => self::PostgreSql,
            default => throw new InvalidArgumentException("Unsupported native proxy protocol [{$protocol}]."),
        };
    }
}
