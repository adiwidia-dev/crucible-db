<?php

namespace App\Enums;

enum SqlPolicyRuleScope: string
{
    case Workspace = 'workspace';
    case ConnectionGroup = 'connection_group';
    case DatabaseConnection = 'database_connection';

    public function key(?int $scopeId): string
    {
        return match ($this) {
            self::Workspace => 'workspace',
            self::ConnectionGroup => "connection_group:{$scopeId}",
            self::DatabaseConnection => "database_connection:{$scopeId}",
        };
    }
}
