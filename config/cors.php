<?php

declare(strict_types=1);

return [

    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT'],

    'allowed_origins' => [],

    'allowed_origins_patterns' => array_values(array_map(
        fn (string $origin): string => '#^'.preg_quote($origin, '#').'$#',
        array_filter(array_map('trim', explode(',', (string) env('WEB_ORIGIN', ''))), fn (string $origin): bool => $origin !== ''),
    )),

    'allowed_headers' => ['Authorization', 'Content-Type', 'X-Request-Id'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
