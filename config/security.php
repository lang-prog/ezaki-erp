<?php

return [
    'auth' => [
        'login_ip_rate_limit' => (int) env('AUTH_LOGIN_IP_RATE_LIMIT', 12),
        'login_account_rate_limit' => (int) env('AUTH_LOGIN_ACCOUNT_RATE_LIMIT', 8),
        'lockout_attempts' => (int) env('AUTH_LOCKOUT_ATTEMPTS', 5),
        'lockout_base_seconds' => (int) env('AUTH_LOCKOUT_BASE_SECONDS', 60),
        'lockout_max_seconds' => (int) env('AUTH_LOCKOUT_MAX_SECONDS', 900),
        'lockout_global_attempts' => (int) env('AUTH_LOCKOUT_GLOBAL_ATTEMPTS', 30),
    ],
    // Comma-separated proxy IPs/CIDRs, REMOTE_ADDR (trust the directly connected proxy) or *. Unset trusts loopback only.
    'trusted_proxies' => env('TRUSTED_PROXIES'),
    'platform' => [
        'mfa_required' => (bool) env('PLATFORM_MFA_REQUIRED', false),
        // Enrollment becomes mandatory from this date (Y-m-d) when set; until then admins can enrol voluntarily.
        'mfa_enforce_from' => env('PLATFORM_MFA_ENFORCE_FROM'),
        'step_up_minutes' => (int) env('PLATFORM_STEP_UP_MINUTES', 10),
        'mfa_attempts_per_minute' => (int) env('PLATFORM_MFA_ATTEMPTS', 5),
        'write_rate_limit' => (int) env('PLATFORM_WRITE_RATE_LIMIT', 40),
        'min_password_length' => 12,
    ],
    'audit' => [
        'retention_days' => (int) env('AUDIT_RETENTION_DAYS', 730),
        'login_log_retention_days' => (int) env('LOGIN_LOG_RETENTION_DAYS', 180),
    ],
    'registration' => [
        'stale_unverified_hours' => (int) env('REGISTRATION_STALE_HOURS', 24),
    ],
    'license' => [
        'verify_write_interval_minutes' => (int) env('LICENSE_VERIFY_WRITE_MINUTES', 10),
    ],
    'tokens' => [
        'ttl_minutes' => (int) env('API_TOKEN_TTL_MINUTES', 43200),
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
    'csp' => [
        'report_only' => (bool) env('SECURITY_CSP_REPORT_ONLY', true),
        'policy' => env('SECURITY_CSP_POLICY', "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self' data:; connect-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'self'"),
    ],
];
