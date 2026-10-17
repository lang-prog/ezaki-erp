<?php

// Global token lifetime stays null so already-issued integration tokens keep working during rollout;
// new tokens receive an explicit expires_at (security.tokens.ttl_minutes) and the company-api ability.
return [
    'expiration' => env('SANCTUM_EXPIRATION') !== null ? (int) env('SANCTUM_EXPIRATION') : null,
];
