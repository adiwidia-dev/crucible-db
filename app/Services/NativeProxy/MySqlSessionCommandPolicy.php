<?php

namespace App\Services\NativeProxy;

use App\Enums\AccessMode;

class MySqlSessionCommandPolicy
{
    private const AllowedVariables = '(autocommit|transaction_isolation|transaction_read_only|sql_mode|time_zone|character_set_results|net_read_timeout|net_write_timeout|wait_timeout|interactive_timeout)';

    private const InspectableVariables = [
        'autocommit',
        'character_set_results',
        'interactive_timeout',
        'lower_case_table_names',
        'net_read_timeout',
        'net_write_timeout',
        'offline_mode',
        'sql_mode',
        'time_zone',
        'transaction_isolation',
        'transaction_read_only',
        'version',
        'version_comment',
        'version_compile_os',
        'wait_timeout',
    ];

    public function decide(AccessMode $accessMode, string $sql, string $database): ?ProxyStatementDecision
    {
        $statement = rtrim(trim($this->withoutLeadingDriverComments($sql)), ';');
        if ($statement === '' || preg_match('/;\s*.+$/s', trim($sql)) === 1) {
            return ProxyStatementDecision::deny('multiple_statements');
        }
        if (preg_match('/^(begin|start\s+transaction)(?:\s+(read\s+write|read\s+only))?$/i', $statement, $matches) === 1) {
            if ($accessMode === AccessMode::Read && isset($matches[2]) && strtolower($matches[2]) === 'read write') {
                return ProxyStatementDecision::deny('read_only_transaction');
            }

            return ProxyStatementDecision::allowSession();
        }
        if (preg_match('/^(commit|rollback)(?:\s+work)?$/i', $statement) === 1
            || preg_match('/^(savepoint|release\s+savepoint|rollback\s+to(?:\s+savepoint)?)\s+[a-z_][a-z0-9_$]*$/i', $statement) === 1) {
            return ProxyStatementDecision::allowSession();
        }
        if (preg_match('/^set\s+(?:(?:session|local)\s+|@@(?:(?:session|local)\.)?)?'.self::AllowedVariables.'\s*(?:=|:=)\s*[^;]+$/i', $statement) === 1) {
            if ($accessMode === AccessMode::Read && preg_match('/transaction_read_only\s*(?:=|:=)\s*(?:off|0)/i', $statement) === 1) {
                return ProxyStatementDecision::deny('read_only_transaction');
            }
            if (! $this->allowsTimeout($statement)) {
                return ProxyStatementDecision::deny('invalid_timeout');
            }

            return ProxyStatementDecision::allowSession();
        }
        if (preg_match('/^set\s+(?:character\s+set\s+|names\s+)(?:utf8|utf8mb4|\'utf8\'|\'utf8mb4\'|"utf8"|"utf8mb4")(?:\s+collate\s+[a-z0-9_]+)?$/i', $statement) === 1
            || preg_match('/^set\s+sql_safe_updates\s*(?:=|:=)\s*1$/i', $statement) === 1) {
            return ProxyStatementDecision::allowSession();
        }
        if (preg_match('/^use\s+(?:`)?([a-zA-Z0-9_]+)(?:`)?$/i', $statement, $matches) === 1) {
            return hash_equals($database, $matches[1]) ? ProxyStatementDecision::allowSession() : ProxyStatementDecision::deny('cross_database');
        }
        if (preg_match('/^show\s+(?:databases|schemas)$/i', $statement) === 1) {
            return ProxyStatementDecision::allowSession('lease_databases');
        }
        if ($this->isLeaseScopedMetadataStatement($statement, $database)) {
            return ProxyStatementDecision::allowSession();
        }
        if (preg_match('/^show\s+(?:(?:session|local)\s+)?variables$/i', $statement) === 1) {
            return ProxyStatementDecision::allowSession();
        }
        if (preg_match('/^show\s+(?:procedure|function)\s+status\s+where\s+`?db`?\s*=\s*([\'\"])([A-Za-z0-9_$-]+)\1$/i', $statement, $matches) === 1) {
            return $this->databaseSpecifierAllows($database, $matches[2])
                ? ProxyStatementDecision::allowSession()
                : ProxyStatementDecision::deny('cross_database');
        }
        if ($this->isInspectableVariableStatement($statement)) {
            return ProxyStatementDecision::allowSession();
        }
        if (preg_match('/^show\s+(?:session\s+)?status(?:\s+like\s+\'[a-z0-9_]+\')?$/i', $statement) === 1) {
            return ProxyStatementDecision::allowSession();
        }
        if (preg_match('/^show\s+(?:character\s+set|charset)(?:\s+(?:like\s+\'[a-z0-9_%]+\'|where\s+charset\s*=\s*\'[a-z0-9_]+\'))?$/i', $statement) === 1) {
            return ProxyStatementDecision::allowSession();
        }
        if (preg_match('/^show\s+(?:warnings|errors|count\(\*\)\s+(?:warnings|errors)|collation|engines|plugins)$/i', $statement) === 1) {
            return ProxyStatementDecision::allowSession();
        }
        if (preg_match('/^show\b/i', $statement) === 1) {
            return ProxyStatementDecision::deny('unsupported_show_statement');
        }
        if (preg_match('/^(set\s+(?:global|persist)|set\s+(?:password|role)|lock\s+tables|unlock\s+tables|flush|reset|kill)\b/i', $statement) === 1) {
            return ProxyStatementDecision::deny();
        }

        return null;
    }

    private function withoutLeadingDriverComments(string $sql): string
    {
        return preg_replace('/\A(?:\s*\/\*(?![!+])(?:[^*]|\*(?!\/))*\*\/)+\s*/s', '', $sql) ?? $sql;
    }

    private function isLeaseScopedMetadataStatement(string $statement, string $database): bool
    {
        $identifier = '(?:`(?:``|[^`])+`|[A-Za-z0-9_]+)';
        $qualifiedIdentifier = '(?:'.$identifier.'\.)?'.$identifier;
        $filter = '(?:\s+(?:like\s+(?:\'(?:\'\'|\\\\.|[^\'])*\'|"(?:""|\\\\.|[^"])*"|'.$identifier.')|where\s+[^;]+))?';

        if (preg_match('/^show\s+(?:extended\s+)?(?:full\s+)?tables(?:\s+(?:from|in)\s+('.$identifier.'))?'.$filter.'$/i', $statement, $matches) === 1) {
            return $this->databaseSpecifierAllows($database, $matches[1] ?? null);
        }

        if (preg_match('/^show\s+table\s+status(?:\s+(?:from|in)\s+('.$identifier.'))?'.$filter.'$/i', $statement, $matches) === 1) {
            return $this->databaseSpecifierAllows($database, $matches[1] ?? null);
        }

        if (preg_match('/^show\s+(?:triggers|events)\s+(?:from|in)\s+('.$identifier.')'.$filter.'$/i', $statement, $matches) === 1) {
            return $this->databaseSpecifierAllows($database, $matches[1]);
        }

        if (preg_match('/^show\s+(?:full\s+)?(?:columns|fields)\s+(?:from|in)\s+('.$qualifiedIdentifier.')(?:\s+(?:from|in)\s+('.$identifier.'))?'.$filter.'$/i', $statement, $matches) === 1) {
            return $this->qualifiedIdentifierAllows($database, $matches[1])
                && $this->databaseSpecifierAllows($database, $matches[2] ?? null);
        }

        if (preg_match('/^show\s+(?:index|indexes|keys)\s+(?:from|in)\s+('.$qualifiedIdentifier.')(?:\s+(?:from|in)\s+('.$identifier.'))?(?:\s+where\s+[^;]+)?$/i', $statement, $matches) === 1) {
            return $this->qualifiedIdentifierAllows($database, $matches[1])
                && $this->databaseSpecifierAllows($database, $matches[2] ?? null);
        }

        if (preg_match('/^show\s+create\s+(?:table|view)\s+('.$qualifiedIdentifier.')$/i', $statement, $matches) === 1) {
            return $this->qualifiedIdentifierAllows($database, $matches[1]);
        }

        return false;
    }

    private function databaseSpecifierAllows(string $database, ?string $specifier): bool
    {
        if ($specifier === null || $specifier === '') {
            return true;
        }

        return hash_equals(mb_strtolower($database), mb_strtolower($this->unquoteIdentifier($specifier)));
    }

    private function isInspectableVariableStatement(string $statement): bool
    {
        if (preg_match('/^show\s+(?:session\s+)?variables\s+like\s+\'([a-z_]+)\'$/i', $statement, $matches) !== 1) {
            return false;
        }

        return in_array(mb_strtolower($matches[1]), self::InspectableVariables, true);
    }

    private function qualifiedIdentifierAllows(string $database, string $identifier): bool
    {
        if (! str_contains($identifier, '.')) {
            return true;
        }

        [$qualifier] = explode('.', $identifier, 2);

        return $this->databaseSpecifierAllows($database, $qualifier);
    }

    private function unquoteIdentifier(string $identifier): string
    {
        $identifier = trim($identifier);
        if (str_starts_with($identifier, '`') && str_ends_with($identifier, '`')) {
            return str_replace('``', '`', substr($identifier, 1, -1));
        }

        return $identifier;
    }

    private function allowsTimeout(string $statement): bool
    {
        if (preg_match('/\b(net_read_timeout|net_write_timeout|wait_timeout|interactive_timeout)\b\s*(?:=|:=)\s*([0-9]+)\b/i', $statement, $matches) !== 1) {
            return true;
        }

        return (int) $matches[2] > 0 && (int) $matches[2] <= 900;
    }
}
