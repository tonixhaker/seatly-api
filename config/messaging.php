<?php

declare(strict_types=1);

return [
    'rabbitmq' => [
        'url' => env('RABBITMQ_URL', ''),
        'exchange' => 'seatly.events',
    ],
    'schema_path' => base_path('docs/events'),
];
