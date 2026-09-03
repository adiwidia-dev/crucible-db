<?php

return [
    'enabled' => (bool) env('NATIVE_PROXY_ENABLED', false),

    'username_prefix' => 'crucible_',

    'control_secret' => env('NATIVE_PROXY_CONTROL_SECRET'),

    'control_clock_skew_seconds' => 30,

    'control_nonce_ttl_seconds' => 60,

    'max_connections' => (int) env('NATIVE_PROXY_MAX_CONNECTIONS', 100),

    'max_connections_per_user' => (int) env('NATIVE_PROXY_MAX_CONNECTIONS_PER_USER', 10),

    'reservation_seconds' => 15,

    'device_authorization_rate_limit' => 20,

    'device_token_rate_limit' => 60,

    'max_pending_device_authorizations_per_lease' => (int) env('NATIVE_PROXY_MAX_PENDING_DEVICE_AUTHORIZATIONS_PER_LEASE', 3),

    'connection_stale_seconds' => (int) env('NATIVE_PROXY_CONNECTION_STALE_SECONDS', 60),

    'statement_stale_seconds' => (int) env('NATIVE_PROXY_STATEMENT_STALE_SECONDS', 900),

    'revocation_channel' => env('NATIVE_PROXY_REVOCATION_CHANNEL', 'native-proxy:lease-revoked'),

    'health_url' => env('NATIVE_PROXY_HEALTH_URL'),

    'expected_version' => env('NATIVE_PROXY_EXPECTED_VERSION'),

    'cli_download_url' => env('NATIVE_PROXY_CLI_DOWNLOAD_URL', 'https://github.com/adiwidia-dev/crucible-db/releases/latest'),

    'health_cache_seconds' => 30,
];
