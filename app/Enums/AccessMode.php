<?php

namespace App\Enums;

enum AccessMode: string
{
    case None = 'none';
    case Read = 'read';
    case Write = 'write';

    public function allows(QueryType $queryType): bool
    {
        return match ($this) {
            self::Write => true,
            self::Read => $queryType === QueryType::Read,
            self::None => false,
        };
    }
}
