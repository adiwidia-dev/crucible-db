<?php

namespace App\Services;

use Illuminate\Http\Request;

class InitialSetupAccess
{
    private const SessionKey = 'initial_setup.authorization';

    public function isConfigured(): bool
    {
        return strlen($this->token()) >= 32;
    }

    public function grant(Request $request, string $candidate): bool
    {
        if (! $this->isConfigured() || ! hash_equals($this->token(), $candidate)) {
            return false;
        }

        $request->session()->put(self::SessionKey, $this->fingerprint());

        return true;
    }

    public function isGranted(Request $request): bool
    {
        $grantedFingerprint = $request->session()->get(self::SessionKey);

        return $this->isConfigured()
            && is_string($grantedFingerprint)
            && hash_equals($this->fingerprint(), $grantedFingerprint);
    }

    public function revoke(Request $request): void
    {
        $request->session()->forget(self::SessionKey);
    }

    private function fingerprint(): string
    {
        return hash_hmac('sha256', 'crucible-initial-setup-access-v1', $this->token());
    }

    private function token(): string
    {
        return (string) config('security.initial_setup_token');
    }
}
