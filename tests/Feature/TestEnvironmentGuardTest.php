<?php

declare(strict_types=1);

it('pins the test database and outbound integrations in $_SERVER, where a container environment would otherwise win', function (): void {
    expect($_SERVER['DB_CONNECTION'] ?? null)->toBe('pgsql')
        ->and($_SERVER['DB_DATABASE'] ?? null)->toBe('seatly_test')
        ->and($_SERVER['RABBITMQ_URL'] ?? null)->toBe('')
        ->and($_SERVER['REALTIME_URL'] ?? null)->toBe('http://realtime.invalid')
        ->and($_SERVER['INTERNAL_TOKEN'] ?? null)->toBe(str_repeat('5eed', 16))
        ->and($_SERVER['APP_ENV'] ?? null)->toBe('testing')
        ->and(config('database.connections.pgsql.database'))->toBe('seatly_test')
        ->and(config('messaging.rabbitmq.url'))->toBe('')
        ->and(config('realtime.base_url'))->toBe('http://realtime.invalid')
        ->and(config('realtime.internal_token'))->toBe(str_repeat('5eed', 16));
});

it('refuses to boot the application when the database is not seatly_test', function (): void {
    $pinned = $_SERVER['DB_DATABASE'];
    $_SERVER['DB_DATABASE'] = 'seatly';

    try {
        expect(fn () => $this->createApplication())
            ->toThrow(RuntimeException::class, 'Refusing to run tests against ["pgsql","seatly"]');
    } finally {
        $_SERVER['DB_DATABASE'] = $pinned;
    }
});
