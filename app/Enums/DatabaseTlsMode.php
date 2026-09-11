<?php

namespace App\Enums;

enum DatabaseTlsMode: string
{
    case Disabled = 'disabled';
    case Preferred = 'preferred';
    case Required = 'required';
    case VerifyCa = 'verify_ca';
    case VerifyIdentity = 'verify_identity';

    public function requiresCaCertificate(): bool
    {
        return match ($this) {
            self::VerifyCa, self::VerifyIdentity => true,
            self::Disabled, self::Preferred, self::Required => false,
        };
    }

    public function postgreSqlSslMode(): string
    {
        return match ($this) {
            self::Disabled => 'disable',
            self::Preferred => 'prefer',
            self::Required => 'require',
            self::VerifyCa => 'verify-ca',
            self::VerifyIdentity => 'verify-full',
        };
    }

    public function verifiesServerCertificate(): bool
    {
        return match ($this) {
            self::VerifyCa, self::VerifyIdentity => true,
            self::Disabled, self::Preferred, self::Required => false,
        };
    }
}
