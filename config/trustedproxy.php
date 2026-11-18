<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | Only these addresses may set X-Forwarded-For / -Proto. Trusting '*' lets
    | anyone who reaches Octane directly spoof their IP and bypass every
    | IP-based rate limit. Default: the local Nginx reverse proxy. Use a
    | comma-separated list (CIDR allowed) for a load balancer; if Cloudflare
    | sits in front, let Nginx restore the client IP (real_ip module).
    |
    */

    'proxies' => env('TRUSTED_PROXIES', '127.0.0.1,::1'),

];
