<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | The framework's TrustProxies global middleware reads this key at request
    | time. Accepts "*" (trust the calling proxy) or a comma-separated list of
    | IP addresses / CIDR ranges. Leave unset (the default) when the app is
    | not behind a TLS-terminating reverse proxy — X-Forwarded-* headers are
    | then ignored.
    |
    */

    'proxies' => env('TRUSTED_PROXIES'),

];
