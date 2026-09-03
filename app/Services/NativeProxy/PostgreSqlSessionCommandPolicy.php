<?php

namespace App\Services\NativeProxy;

use App\Enums\AccessMode;

class PostgreSqlSessionCommandPolicy
{
    private const AllowedVariables = '(application_name|client_encoding|client_min_messages|datestyle|timezone|extra_float_digits|bytea_output|standard_conforming_strings|search_path|statement_timeout|lock_timeout|idle_in_transaction_session_timeout)';

    public function decide(AccessMode $accessMode, string $sql): ?ProxyStatementDecision
    {
        $statement = trim($sql);
        if (! $this->isSingleStatement($statement)) {
            return ProxyStatementDecision::deny('multiple_statements');
        }
        $statement = rtrim($statement, ';');

        if (preg_match('/^(begin|start\s+transaction)(?:\s+(?:work|transaction))?(?:\s+isolation\s+level\s+(?:read\s+committed|repeatable\s+read|serializable))?(?:\s+(read\s+write|read\s+only))?$/i', $statement, $matches) === 1) {
            if ($accessMode === AccessMode::Read && isset($matches[2]) && strtolower($matches[2]) === 'read write') {
                return ProxyStatementDecision::deny('read_only_transaction');
            }

            return ProxyStatementDecision::allowSession();
        }
        if (preg_match('/^(commit|end|rollback|abort)(?:\s+(?:work|transaction))?$/i', $statement) === 1
            || preg_match('/^(savepoint|release\s+savepoint|rollback\s+to(?:\s+savepoint)?)\s+[a-z_][a-z0-9_$]*$/i', $statement) === 1) {
            return ProxyStatementDecision::allowSession();
        }
        if (preg_match('/^set\s+(?:(?:session|local)\s+)?'.self::AllowedVariables.'(?:\s+to\s+|\s*=\s*)[^;]+$/i', $statement) === 1) {
            if (preg_match('/\bdefault_transaction_read_only\b|\bset\s+role\b|\bsession\s+authorization\b/i', $statement) === 1) {
                return ProxyStatementDecision::deny();
            }
            if (! $this->allowsTimeout($statement)) {
                return ProxyStatementDecision::deny('invalid_timeout');
            }
            if (! $this->allowsStandardConformingStrings($statement)) {
                return ProxyStatementDecision::deny('unsafe_string_escaping');
            }

            return ProxyStatementDecision::allowSession();
        }
        if (preg_match('/^(show|reset)\s+'.self::AllowedVariables.'$/i', $statement) === 1) {
            return ProxyStatementDecision::allowSession();
        }
        if (preg_match('/^(show|reset)\b/i', $statement) === 1) {
            return ProxyStatementDecision::deny('unsupported_session_variable');
        }
        if (preg_match('/^(set\s+role|set\s+session\s+authorization|discard|listen|notify|unlisten|prepare|execute|deallocate)\b/i', $statement) === 1) {
            return ProxyStatementDecision::deny();
        }

        return null;
    }

    private function isSingleStatement(string $statement): bool
    {
        return $statement !== '' && preg_match('/;\s*.+$/s', $statement) !== 1;
    }

    private function allowsTimeout(string $statement): bool
    {
        if (preg_match('/\b(statement_timeout|lock_timeout|idle_in_transaction_session_timeout)\b(?:\s+to\s+|\s*=\s*)([0-9]+)\b/i', $statement, $matches) !== 1) {
            return true;
        }

        return (int) $matches[2] > 0 && (int) $matches[2] <= 900000;
    }

    private function allowsStandardConformingStrings(string $statement): bool
    {
        if (preg_match('/\bstandard_conforming_strings\b(?:\s+to\s+|\s*=\s*)(.+)$/i', $statement, $matches) !== 1) {
            return true;
        }

        $value = strtolower(trim($matches[1], " \t\n\r\0\x0B'\""));

        return in_array($value, ['on', 'true', 'yes', '1', 'default'], true);
    }
}
