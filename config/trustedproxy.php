<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | The reverse proxies whose X-Forwarded-* headers are believed, which is
    | what makes the client IP, the https scheme and signed URLs correct
    | behind a TLS-terminating load balancer. Leave empty when PHP is reached
    | directly. Use "*" when every request arrives through the host's proxy,
    | or a comma-separated list of proxy addresses / CIDR ranges.
    |
    */

    'proxies' => env('TRUSTED_PROXIES') ?: null,

];
