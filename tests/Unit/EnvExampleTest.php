<?php

declare(strict_types=1);

use Dotenv\Dotenv;

$composeServices = ['postgres', 'redis', 'rabbitmq', 'api', 'realtime', 'web'];

$variables = Dotenv::parse((string) file_get_contents(dirname(__DIR__, 2).'/.env.example'));

$hostDataset = [];

foreach ($variables as $name => $value) {
    $hostDataset[$name] = [(string) $value];
}

it('carries the connection variables, so the check below cannot pass vacuously', function () use ($variables): void {
    expect(array_keys($variables))->toContain('DB_HOST', 'REDIS_HOST');
});

it('ships a non-empty database password, so postgres initialises standalone', function () use ($variables): void {
    expect($variables['DB_PASSWORD'] ?? '')->not->toBe('');
});

it('points every value at a host reachable outside compose, not at a compose service name', function (string $value) use ($composeServices): void {
    $host = parse_url($value, PHP_URL_HOST);

    expect($composeServices)->not->toContain(is_string($host) ? $host : $value);
})->with($hostDataset);
