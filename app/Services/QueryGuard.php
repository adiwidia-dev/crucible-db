<?php

namespace App\Services;

use App\Enums\QueryType;
use App\Enums\SqlStatementFamily;
use Illuminate\Validation\ValidationException;

class QueryGuard
{
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

        if (preg_match('/\b(grant|revoke|create\s+user|alter\s+(?:user|role|database|system)|copy|load\s+data|load_file|into\s+outfile)\b/i', $this->executableSql($singleStatement)) === 1) {
            throw ValidationException::withMessages([
                'sql' => 'Administrative, file, and security-management SQL statements are blocked.',
            ]);
        }

        if (preg_match('/^explain\b/i', $singleStatement) === 1 && preg_match('/\banalyze\b/i', $this->executableSql($singleStatement)) === 1) {
            throw ValidationException::withMessages([
                'sql' => 'EXPLAIN ANALYZE is not supported because it can execute the explained statement.',
            ]);
        }

        $statementFamily = $this->statementFamily($singleStatement);

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

        return $this->statementFamily($statement)->queryType();
    }

    /**
     * @throws ValidationException
     */
    private function statementFamily(string $statement): SqlStatementFamily
    {
        $executableSql = ltrim($this->executableSql($statement));

        return match (true) {
            preg_match('/^(select|show|describe|desc|explain)\b/i', $executableSql) === 1 => SqlStatementFamily::Read,
            preg_match('/^insert\b/i', $executableSql) === 1 => SqlStatementFamily::Insert,
            preg_match('/^update\b/i', $executableSql) === 1 => SqlStatementFamily::Update,
            preg_match('/^delete\b/i', $executableSql) === 1 => SqlStatementFamily::Delete,
            preg_match('/^create\s+(temporary\s+)?table\b/i', $executableSql) === 1 => SqlStatementFamily::CreateTable,
            preg_match('/^alter\s+table\b/i', $executableSql) === 1 => SqlStatementFamily::AlterTable,
            preg_match('/^drop\s+(temporary\s+)?table\b/i', $executableSql) === 1 => SqlStatementFamily::DropTable,
            preg_match('/^truncate(?:\s+table)?\b/i', $executableSql) === 1 => SqlStatementFamily::TruncateTable,
            default => throw ValidationException::withMessages([
                'sql' => 'This SQL statement is not supported by the governed SQL policy.',
            ]),
        };
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
