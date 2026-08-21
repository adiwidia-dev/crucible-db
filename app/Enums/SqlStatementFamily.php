<?php

namespace App\Enums;

use App\Services\ApplicationSettings;

enum SqlStatementFamily: string
{
    case Read = 'read';

    case Insert = 'insert';

    case Update = 'update';

    case Delete = 'delete';

    case CreateTable = 'create_table';

    case AlterTable = 'alter_table';

    case DropTable = 'drop_table';

    case TruncateTable = 'truncate_table';

    public function settingKey(): string
    {
        return match ($this) {
            self::Read => ApplicationSettings::SqlReadQueriesEnabled,
            self::Insert => ApplicationSettings::SqlInsertEnabled,
            self::Update => ApplicationSettings::SqlUpdateEnabled,
            self::Delete => ApplicationSettings::SqlDeleteEnabled,
            self::CreateTable => ApplicationSettings::SqlCreateTableEnabled,
            self::AlterTable => ApplicationSettings::SqlAlterTableEnabled,
            self::DropTable => ApplicationSettings::SqlDropTableEnabled,
            self::TruncateTable => ApplicationSettings::SqlTruncateTableEnabled,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Read => 'Read queries',
            self::Insert => 'INSERT',
            self::Update => 'UPDATE',
            self::Delete => 'DELETE',
            self::CreateTable => 'CREATE TABLE',
            self::AlterTable => 'ALTER TABLE',
            self::DropTable => 'DROP TABLE',
            self::TruncateTable => 'TRUNCATE TABLE',
        };
    }

    public function queryType(): QueryType
    {
        return $this === self::Read ? QueryType::Read : QueryType::Write;
    }

    public function isEnabledByDefault(): bool
    {
        return ! in_array($this, [self::AlterTable, self::DropTable, self::TruncateTable], true);
    }
}
