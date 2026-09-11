<?php

namespace App\Services;

use App\Enums\DatabaseDriver;

class SqlPolicyStatementAnalyzer
{
    public function __construct(private readonly QueryGuard $queryGuard) {}

    public function canonicalSql(string $sql): string
    {
        return $this->queryGuard->canonicalSql($sql);
    }

    public function exactFingerprint(string $sql): string
    {
        return hash('sha256', $this->canonicalSql($sql));
    }

    /**
     * Return a reusable statement shape only when the dialect-aware parser can
     * identify the complete top-level operation conservatively.
     *
     * @return array{signature:string,label:string,match_value:string}|null
     */
    public function statementShape(DatabaseDriver $driver, string $sql): ?array
    {
        $tokens = $this->tokens($this->canonicalSql($sql));

        if ($tokens === null || $tokens === [] || $tokens[0] !== 'CREATE') {
            return null;
        }

        $index = 1;
        $modifier = null;
        $allowedModifiers = $driver === DatabaseDriver::PostgreSql
            ? ['UNIQUE']
            : ['UNIQUE', 'FULLTEXT', 'SPATIAL'];

        if (in_array($tokens[$index] ?? null, $allowedModifiers, true)) {
            $modifier = $tokens[$index];
            $index++;
        }

        if (($tokens[$index] ?? null) !== 'INDEX') {
            return null;
        }

        $index++;
        $concurrently = false;

        if ($driver === DatabaseDriver::PostgreSql && ($tokens[$index] ?? null) === 'CONCURRENTLY') {
            $concurrently = true;
            $index++;
        }

        if (($tokens[$index] ?? null) === 'IF') {
            if (($tokens[$index + 1] ?? null) !== 'NOT' || ($tokens[$index + 2] ?? null) !== 'EXISTS') {
                return null;
            }

            $index += 3;
        }

        if (! $this->isIdentifier($tokens[$index] ?? null)) {
            return null;
        }

        $index++;

        if ($driver === DatabaseDriver::MySql && ($tokens[$index] ?? null) === 'USING') {
            $index += 2;
        }

        if (($tokens[$index] ?? null) !== 'ON') {
            return null;
        }

        $index++;

        if ($driver === DatabaseDriver::PostgreSql && ($tokens[$index] ?? null) === 'ONLY') {
            $index++;
        }

        $index = $this->consumeQualifiedIdentifier($tokens, $index);

        if ($index === null) {
            return null;
        }

        if ($driver === DatabaseDriver::PostgreSql && ($tokens[$index] ?? null) === 'USING') {
            if (! $this->isIdentifier($tokens[$index + 1] ?? null)) {
                return null;
            }

            $index += 2;
        }

        if (($tokens[$index] ?? null) !== '(' || ! $this->hasClosingParenthesis($tokens, $index)) {
            return null;
        }

        $variant = mb_strtolower($modifier ?? 'standard');
        $concurrency = $concurrently ? '.concurrently' : '';
        $signature = "{$driver->value}.create_index.{$variant}{$concurrency}.v1";
        $labelParts = array_filter(['CREATE', $modifier, 'INDEX', $concurrently ? 'CONCURRENTLY' : null]);

        return [
            'signature' => $signature,
            'label' => implode(' ', $labelParts),
            'match_value' => hash('sha256', $signature),
        ];
    }

    /**
     * @return list<string>|null
     */
    private function tokens(string $sql): ?array
    {
        $tokens = [];
        $length = strlen($sql);
        $parenthesisDepth = 0;

        for ($index = 0; $index < $length;) {
            $current = $sql[$index];
            $next = $sql[$index + 1] ?? '';

            if (ctype_space($current)) {
                $index++;

                continue;
            }

            if ($current === '-' && $next === '-') {
                $lineEnd = strpos($sql, "\n", $index + 2);
                $index = $lineEnd === false ? $length : $lineEnd + 1;

                continue;
            }

            if ($current === '/' && $next === '*') {
                $commentEnd = strpos($sql, '*/', $index + 2);

                if ($commentEnd === false) {
                    return null;
                }

                $index = $commentEnd + 2;

                continue;
            }

            if (in_array($current, ["'", '"', '`'], true)) {
                $quote = $current;
                $closed = false;
                $index++;

                while ($index < $length) {
                    if ($sql[$index] === $quote && ($sql[$index + 1] ?? '') === $quote) {
                        $index += 2;

                        continue;
                    }

                    if ($sql[$index] === $quote) {
                        $closed = true;
                        $index++;

                        break;
                    }

                    if ($sql[$index] === '\\' && $quote !== '`') {
                        $index += 2;

                        continue;
                    }

                    $index++;
                }

                if (! $closed) {
                    return null;
                }

                $tokens[] = $quote === "'" ? '<LITERAL>' : '<IDENTIFIER>';

                continue;
            }

            if ($current === '$' && preg_match('/\G\$[A-Za-z_][A-Za-z0-9_]*\$|\G\$\$/', $sql, $matches, 0, $index) === 1) {
                $tag = $matches[0];
                $end = strpos($sql, $tag, $index + strlen($tag));

                if ($end === false) {
                    return null;
                }

                $tokens[] = '<LITERAL>';
                $index = $end + strlen($tag);

                continue;
            }

            if (ctype_alpha($current) || $current === '_') {
                if (preg_match('/\G[A-Za-z_][A-Za-z0-9_$]*/', $sql, $matches, 0, $index) !== 1) {
                    return null;
                }

                $identifier = $matches[0];
                $tokens[] = mb_strtoupper($identifier);
                $index += strlen($identifier);

                continue;
            }

            if (ctype_digit($current)) {
                if (preg_match('/\G\d+(?:\.\d+)?/', $sql, $matches, 0, $index) !== 1) {
                    return null;
                }

                $number = $matches[0];
                $tokens[] = '<LITERAL>';
                $index += strlen($number);

                continue;
            }

            if ($current === '(') {
                $parenthesisDepth++;
            } elseif ($current === ')') {
                $parenthesisDepth--;

                if ($parenthesisDepth < 0) {
                    return null;
                }
            }

            $tokens[] = $current;
            $index++;
        }

        return $parenthesisDepth === 0 ? $tokens : null;
    }

    private function isIdentifier(?string $token): bool
    {
        return $token === '<IDENTIFIER>' || ($token !== null && preg_match('/^[A-Z_][A-Z0-9_$]*$/', $token) === 1);
    }

    /**
     * @param  list<string>  $tokens
     */
    private function consumeQualifiedIdentifier(array $tokens, int $index): ?int
    {
        if (! $this->isIdentifier($tokens[$index] ?? null)) {
            return null;
        }

        $index++;

        while (($tokens[$index] ?? null) === '.') {
            if (! $this->isIdentifier($tokens[$index + 1] ?? null)) {
                return null;
            }

            $index += 2;
        }

        return $index;
    }

    /**
     * @param  list<string>  $tokens
     */
    private function hasClosingParenthesis(array $tokens, int $openingIndex): bool
    {
        $depth = 0;

        for ($index = $openingIndex; $index < count($tokens); $index++) {
            if ($tokens[$index] === '(') {
                $depth++;
            } elseif ($tokens[$index] === ')') {
                $depth--;

                if ($depth === 0) {
                    return true;
                }
            }
        }

        return false;
    }
}
