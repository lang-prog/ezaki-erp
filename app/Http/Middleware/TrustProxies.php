<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;

final class TrustProxies extends Middleware
{
    /**
     * Trusted reverse proxies come from TRUSTED_PROXIES (comma-separated IPs/CIDRs, REMOTE_ADDR, or *).
     * When unset only loopback is trusted, so forwarded headers cannot spoof client IPs on direct connections.
     * Production behind a proxy must set an explicit list (see DEPLOYMENT.md).
     */
    protected $proxies;

    public function __construct()
    {
        $configured = config('security.trusted_proxies', env('TRUSTED_PROXIES'));
        if ($configured === null || trim((string) $configured) === '') {
            $this->proxies = ['127.0.0.1', '::1'];

            return;
        }
        $this->proxies = in_array(trim((string) $configured), ['*', '**'], true)
            ? trim((string) $configured)
            : array_values(array_filter(array_map('trim', explode(',', (string) $configured))));
    }
}
