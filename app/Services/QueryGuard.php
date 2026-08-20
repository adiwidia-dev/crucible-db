<?php

namespace App\Services;

use App\Enums\QueryType;
use Illuminate\Validation\ValidationException;

class QueryGuard
{
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

        if (preg_match('/\b(drop|alter|truncate|grant|revoke|create\s+user|copy|load\s+data|load_file|into\s+outfile)\b/i', $singleStatement) === 1) {
            throw ValidationException::withMessages([
                'sql' => 'Administrative, file, and destructive DDL statements are blocked.',
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

        if (preg_match('/^explain\b/i', $statement) === 1 && preg_match('/\banalyze\b/i', $this->executableSql($statement)) === 1) {
            throw ValidationException::withMessages([
                'sql' => 'EXPLAIN ANALYZE is not supported because it can execute the explained statement.',
            ]);
        }

        if (preg_match('/^(select|show|describe|desc|explain)\b/i', $statement) === 1) {
            return QueryType::Read;
        }

        if (preg_match('/^(insert|update|delete)\b/i', $statement) === 1) {
            return QueryType::Write;
        }

        if (preg_match('/^create\s+(temporary\s+)?table\b/i', $statement) === 1) {
            return QueryType::Write;
        }

        throw ValidationException::withMessages([
            'sql' => 'Only SELECT, SHOW, DESCRIBE, EXPLAIN, INSERT, UPDATE, DELETE, and CREATE TABLE statements are supported in the MVP.',
        ]);
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
