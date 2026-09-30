<?php

return [
    'auth' => [
        'login_ip_rate_limit' => (int) env('AUTH_LOGIN_IP_RATE_LIMIT', 12),
        'login_account_rate_limit' => (int) env('AUTH_LOGIN_ACCOUNT_RATE_LIMIT', 8),
        'lockout_attempts' => (int) env('AUTH_LOCKOUT_ATTEMPTS', 5),
        'lockout_base_seconds' => (int) env('AUTH_LOCKOUT_BASE_SECONDS', 60),
        'lockout_max_seconds' => (int) env('AUTH_LOCKOUT_MAX_SECONDS', 900),
    ],
    'api' => [
        'read_rate_limit' => (int) env('API_READ_RATE_LIMIT', 120),
        'write_rate_limit' => (int) env('API_WRITE_RATE_LIMIT', 60),
        'report_rate_limit' => (int) env('API_REPORT_RATE_LIMIT', 20),
        'export_rate_limit' => (int) env('API_EXPORT_RATE_LIMIT', 10),
    ],
    'cors' => [
        'allowed_origins' => array_values(array_filter(array_map(
            static fn (string $origin): string => trim($origin),
            explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost')),
        ))),
    ],
    'hsts' => [
        'enabled' => (bool) env('SECURITY_HSTS_ENABLED', true),
        'max_age' => (int) env('SECURITY_HSTS_MAX_AGE', 31536000),
        'include_subdomains' => (bool) env('SECURITY_HSTS_INCLUDE_SUBDOMAINS', true),
    ],
];
