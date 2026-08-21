<?php

namespace App\Services;

use App\Enums\QueryType;
use App\Enums\SqlStatementFamily;
use Illuminate\Validation\ValidationException;

class QueryGuard
{
    private const EmergencyFallbackBlockedSqlPattern = '/\\b(grant|revoke|create\\s+(?:user|role|database|extension|function|procedure|trigger|rule|foreign\\s+data\\s+wrapper|server|publication|subscription)|alter\\s+(?:user|role|database|system|function|procedure|trigger|rule)|drop\\s+(?:user|role|database|extension|function|procedure|trigger|rule|foreign\\s+data\\s+wrapper|server|publication|subscription)|copy|load\\s+data|load_file|into\\s+outfile|vacuum|analyze|reindex|cluster|checkpoint|do|call|prepare|execute|deallocate|discard|lock|listen|notify|unlisten|reset)\\b/i';

    public function __construct(private readonly ApplicationSettings $settings) {}

    public function normalize(string $sql): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", $sql));
    }

    /**
     * @throws ValidationException
     */
    public function validateExecutable(string $sql): string
    {
        $normalized = $this->normalize($sql);

        if ($normalized === '') {
            throw ValidationException::withMessages([
                'sql' => 'The SQL statement is required.',
            ]);
        }

        $singleStatement = $this->stripTrailingTerminator($normalized);

        if ($this->containsStatementTerminator($singleStatement)) {
            throw ValidationException::withMessages([
                'sql' => 'Only one SQL statement may be submitted per request.',
            ]);
        }

        $executableSql = $this->executableSql($singleStatement);

        if (preg_match('/^explain\b/i', ltrim($executableSql)) === 1 && preg_match('/\banalyze\b/i', $executableSql) === 1) {
            throw ValidationException::withMessages([
                'sql' => 'EXPLAIN ANALYZE is not supported because it can execute the explained statement.',
            ]);
        }

        if (preg_match(self::EmergencyFallbackBlockedSqlPattern, $executableSql) === 1) {
            throw ValidationException::withMessages([
                'sql' => 'Administrative, file, security-management, and procedural SQL statements are blocked.',
            ]);
        }

        if (preg_match('/^(begin|start\s+transaction|commit|rollback|savepoint|release\s+savepoint|set\s+transaction)\b/i', ltrim($executableSql)) === 1) {
            throw ValidationException::withMessages([
                'sql' => 'Transaction-control SQL statements are blocked. Submit each executable statement in its own batch position.',
            ]);
        }

        $statementFamily = $this->statementFamily($singleStatement);

        if ($statementFamily === null) {
            if (! $this->settings->allowsEmergencySqlFallback()) {
                throw ValidationException::withMessages([
                    'sql' => 'This SQL statement is not supported by the governed SQL policy.',
                ]);
            }

            return $singleStatement;
        }

        if (! $this->settings->allowsSqlStatementFamily($statementFamily)) {
            throw ValidationException::withMessages([
                'sql' => "{$statementFamily->label()} statements are disabled by the workspace administrator.",
            ]);
        }

        return $singleStatement;
    }

    /**
     * @throws ValidationException
     */
    public function classify(string $sql): QueryType
    {
        $statement = $this->validateExecutable($sql);

        return $this->statementFamily($statement)?->queryType() ?? QueryType::Write;
    }

    /**
     * @throws ValidationException
     */
    public function usesEmergencySqlFallback(string $sql): bool
    {
        $statement = $this->validateExecutable($sql);

        return $this->statementFamily($statement) === null;
    }

    /**
     * Query Access sessions intentionally do not use the emergency fallback.
     *
     * @throws ValidationException
     */
    public function validateSessionExecutable(string $sql): string
    {
        $statement = $this->validateExecutable($sql);

        if ($this->statementFamily($statement) === null) {
            throw ValidationException::withMessages([
                'sql' => 'This SQL statement is not supported in a query access session.',
            ]);
        }

        return $statement;
    }

    /**
     * @throws ValidationException
     */
    private function statementFamily(string $statement): ?SqlStatementFamily
    {
        $executableSql = $this->topLevelExecutableSql($statement);

        return match (true) {
            preg_match('/^(select|show|describe|desc|explain)\b/i', $executableSql) === 1 => SqlStatementFamily::Read,
            preg_match('/^insert\b/i', $executableSql) === 1 => SqlStatementFamily::Insert,
            preg_match('/^update\b/i', $executableSql) === 1 => SqlStatementFamily::Update,
            preg_match('/^delete\b/i', $executableSql) === 1 => SqlStatementFamily::Delete,
            preg_match('/^create\s+(temporary\s+)?table\b/i', $executableSql) === 1 => SqlStatementFamily::CreateTable,
            preg_match('/^alter\s+table\b/i', $executableSql) === 1 => SqlStatementFamily::AlterTable,
            preg_match('/^drop\s+(temporary\s+)?table\b/i', $executableSql) === 1 => SqlStatementFamily::DropTable,
            preg_match('/^truncate(?:\s+table)?\b/i', $executableSql) === 1 => SqlStatementFamily::TruncateTable,
            default => null,
        };
    }

    public function topLevelExecutableSql(string $sql): string
    {
        $executableSql = ltrim($this->executableSql($sql));

        if (preg_match('/^with\b/i', $executableSql) !== 1) {
            return $executableSql;
        }

        $parenthesisDepth = 0;
        $length = strlen($executableSql);

        for ($index = 4; $index < $length; $index++) {
            $character = $executableSql[$index];

            if ($character === '(') {
                $parenthesisDepth++;

                continue;
            }

            if ($character === ')') {
                $parenthesisDepth = max(0, $parenthesisDepth - 1);

                continue;
            }

            if ($parenthesisDepth !== 0) {
                continue;
            }

            if (preg_match('/\G\s*(select|insert|update|delete)\b/i', $executableSql, $matches, PREG_OFFSET_CAPTURE, $index) === 1) {
                return substr($executableSql, $matches[1][1]);
            }
        }

        return $executableSql;
    }

    private function stripTrailingTerminator(string $sql): string
    {
        $statement = rtrim($sql);

        while (str_ends_with($statement, ';')) {
            $statement = rtrim(substr($statement, 0, -1));
        }

        return $statement;
    }

    private function containsStatementTerminator(string $sql): bool
    {
        return str_contains($this->executableSql($sql), ';');
    }

    private function executableSql(string $sql): string
    {
        $length = strlen($sql);
        $executableSql = '';
        $singleQuoted = false;
        $doubleQuoted = false;
        $lineComment = false;
        $blockComment = false;
        $dollarQuoteTag = null;

        for ($index = 0; $index < $length; $index++) {
            $current = $sql[$index];
            $next = $sql[$index + 1] ?? '';

            if ($lineComment) {
                if ($current === "\n") {
                    $lineComment = false;
                    $executableSql .= "\n";
                }

                continue;
            }

            if ($blockComment) {
                if ($current === '*' && $next === '/') {
                    $blockComment = false;
                    $index++;
                }

                continue;
            }

            if ($dollarQuoteTag !== null) {
                $tagLength = strlen($dollarQuoteTag);

                if (substr($sql, $index, $tagLength) === $dollarQuoteTag) {
                    $dollarQuoteTag = null;
                    $index += $tagLength - 1;
                }

                continue;
            }

            if ($singleQuoted) {
                if ($current === "'" && $next === "'") {
                    $index++;

                    continue;
                }

                if ($current === "'") {
                    $singleQuoted = false;
                }

                continue;
            }

            if ($doubleQuoted) {
                if ($current === '"' && $next === '"') {
                    $index++;

                    continue;
                }

                if ($current === '"') {
                    $doubleQuoted = false;
                }

                continue;
            }

            if ($current === '-' && $next === '-') {
                $lineComment = true;
                $executableSql .= ' ';
                $index++;

                continue;
            }

            if ($current === '/' && $next === '*') {
                $blockComment = true;
                $executableSql .= ' ';
                $index++;

                continue;
            }

            if ($current === "'") {
                $singleQuoted = true;
                $executableSql .= ' ';

                continue;
            }

            if ($current === '"') {
                $doubleQuoted = true;
                $executableSql .= ' ';

                continue;
            }

            if ($current === '$' && preg_match('/\G\$[A-Za-z_][A-Za-z0-9_]*\$|\G\$\$/', $sql, $matches, 0, $index) === 1) {
                $dollarQuoteTag = $matches[0];
                $executableSql .= ' ';
                $index += strlen($dollarQuoteTag) - 1;

                continue;
            }

            $executableSql .= $current;
        }

        return $executableSql;
    }
}
