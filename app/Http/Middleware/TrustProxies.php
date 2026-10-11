<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;

final class TrustProxies extends Middleware
{
    /**
     * The reverse proxy addresses used by the hosting provider.
     *
     * A wildcard is safe here because this application is served behind the
     * hosting provider's reverse proxy. Direct HTTP requests without forwarded
     * headers continue to behave as HTTP requests.
     */
    protected $proxies;

    public function __construct()
    {
        $this->proxies = env('TRUSTED_PROXIES', '*');
    }
}
