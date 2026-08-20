<?php

declare(strict_types=1);

$bootAs = function (bool $console, ?string $token, Closure $assertion): void {
    $saved = [
        'APP_RUNNING_IN_CONSOLE' => $_SERVER['APP_RUNNING_IN_CONSOLE'] ?? null,
        'INTERNAL_TOKEN' => $_SERVER['INTERNAL_TOKEN'] ?? null,
    ];

    $_SERVER['APP_RUNNING_IN_CONSOLE'] = $console ? 'true' : 'false';

    if ($token !== null) {
        $_SERVER['INTERNAL_TOKEN'] = $token;
    }

    try {
        $assertion();
    } finally {
        foreach ($saved as $name => $value) {
            if ($value === null) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $value;
            }
        }
    }
};

it('boots in a web context with the pinned token and answers GET /health with 200', function () use ($bootAs): void {
    $bootAs(false, null, function (): void {
        $this->refreshApplication();

        expect($this->app->runningInConsole())->toBeFalse()
            ->and(config('realtime.internal_token'))->toBe(str_repeat('5eed', 16));

        $this->get('/health', ['Accept' => ''])->assertOk();
    });
});

it('refuses to boot in a web context when INTERNAL_TOKEN is not a non-empty string', function (string $raw) use ($bootAs): void {
    $bootAs(false, $raw, function (): void {
        expect(fn () => $this->createApplication())
            ->toThrow(RuntimeException::class, 'INTERNAL_TOKEN');
    });
})->with([
    'empty string' => '',
    'null literal' => 'null',
]);

it('still boots artisan in a console context when INTERNAL_TOKEN is empty', function () use ($bootAs): void {
    $bootAs(true, '', function (): void {
        $app = $this->createApplication();

        expect($app->runningInConsole())->toBeTrue()
            ->and($app->make('config')->get('realtime.internal_token'))->toBe('');
    });
});
