<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class OidcIssuerUrl implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || filter_var($value, FILTER_VALIDATE_URL) === false) {
            $fail('The :attribute must be a valid issuer URL.');

            return;
        }

        $parts = parse_url($value);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            $fail('The :attribute must include a scheme and host.');

            return;
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            $fail('The :attribute cannot include credentials, a query string, or a fragment.');

            return;
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $isLoopbackHost = in_array($host, ['localhost', '127.0.0.1', '[::1]', '::1'], true)
            || str_ends_with($host, '.localhost');

        if ($scheme !== 'https' && ! ($scheme === 'http' && $isLoopbackHost)) {
            $fail('The :attribute must use HTTPS. Plain HTTP is only allowed for loopback development hosts.');
        }
    }
}
