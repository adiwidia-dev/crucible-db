<?php

return [
    'initial_setup_token' => env('CRUCIBLE_INITIAL_SETUP_TOKEN'),

    'content_security_policy' => env(
        'CONTENT_SECURITY_POLICY',
        "base-uri 'self'; object-src 'none'; frame-ancestors 'none'",
    ),

    'content_security_policy_report_only' => env(
        'CONTENT_SECURITY_POLICY_REPORT_ONLY',
        "default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'none'; form-action 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self' data:; connect-src 'self' ws: wss:",
    ),
];
