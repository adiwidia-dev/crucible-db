<?php

namespace App\Support;

use App\Models\NativeProxyLease;

class NativeProxyCredential
{
    public function __construct(
        public readonly NativeProxyLease $lease,
        public readonly string $username,
        public readonly string $password,
    ) {}

    /**
     * @return array{lease_id:string, username:string, password:string, credential_version:int, expires_at:string}
     */
    public function toArray(): array
    {
        return [
            'lease_id' => $this->lease->id,
            'username' => $this->username,
            'password' => $this->password,
            'credential_version' => $this->lease->credential_version,
            'expires_at' => $this->lease->expires_at->toIso8601String(),
        ];
    }
}
