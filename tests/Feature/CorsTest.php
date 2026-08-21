<?php

declare(strict_types=1);

use Illuminate\Testing\TestResponse;

const WEB = 'http://localhost:5173';
const EVIL = 'http://evil.test';

function preflight(string $origin, string $method = 'GET', string $headers = 'authorization'): TestResponse
{
    return test()->call('OPTIONS', '/api/v1/events', [], [], [], [
        'HTTP_ORIGIN' => $origin,
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => $method,
        'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => $headers,
    ]);
}

function assertNoCredentials(TestResponse $response): void
{
    expect($response->headers->get('Access-Control-Allow-Credentials'))->not->toBe('true');
}

function withWebOrigin(string $value, Closure $body): void
{
    $previous = ['server' => $_SERVER['WEB_ORIGIN'] ?? null, 'env' => $_ENV['WEB_ORIGIN'] ?? null];
    $_SERVER['WEB_ORIGIN'] = $_ENV['WEB_ORIGIN'] = $value;

    try {
        config(['cors' => require config_path('cors.php')]);
        $body();
    } finally {
        unset($_SERVER['WEB_ORIGIN'], $_ENV['WEB_ORIGIN']);
        if ($previous['server'] !== null) {
            $_SERVER['WEB_ORIGIN'] = $previous['server'];
        }
        if ($previous['env'] !== null) {
            $_ENV['WEB_ORIGIN'] = $previous['env'];
        }
        config(['cors' => require config_path('cors.php')]);
    }
}

it('answers a preflight from the web origin with that origin and allows Authorization', function (): void {
    $response = preflight(WEB);

    $response->assertNoContent()->assertHeader('Access-Control-Allow-Origin', WEB);
    expect(strtolower((string) $response->headers->get('Access-Control-Allow-Headers')))->toContain('authorization');
    assertNoCredentials($response);
});

it('stamps the web origin on a GET from the web origin', function (): void {
    $response = $this->getJson('/api/v1/events', ['Origin' => WEB]);

    $response->assertOk()->assertHeader('Access-Control-Allow-Origin', WEB);
    assertNoCredentials($response);
});

it('gives no Allow-Origin to a preflight from a foreign origin', function (): void {
    $response = preflight(EVIL);

    $response->assertHeaderMissing('Access-Control-Allow-Origin');
    assertNoCredentials($response);
});

it('gives no Allow-Origin to a GET from a foreign origin', function (): void {
    $response = $this->getJson('/api/v1/events', ['Origin' => EVIL]);

    $response->assertOk()->assertHeaderMissing('Access-Control-Allow-Origin');
    assertNoCredentials($response);
});

it('allows only the methods and headers the web client uses', function (): void {
    $response = preflight(WEB, 'DELETE', 'authorization, content-type, x-request-id');

    $methods = strtoupper((string) $response->headers->get('Access-Control-Allow-Methods'));
    $headers = strtolower((string) $response->headers->get('Access-Control-Allow-Headers'));

    expect($methods)->toContain('GET')->toContain('POST')->toContain('PUT')->not->toContain('DELETE')
        ->and($headers)->toContain('authorization')->toContain('content-type')->toContain('x-request-id')
        ->and($response->headers->get('Access-Control-Expose-Headers'))->toBeNull();
});

it('allows every origin in a comma-separated WEB_ORIGIN, trimmed and without empties', function (): void {
    withWebOrigin(WEB.', http://127.0.0.1:5173,', function (): void {
        expect(config('cors.allowed_origins_patterns'))->toHaveCount(2);

        $this->getJson('/api/v1/events', ['Origin' => 'http://127.0.0.1:5173'])
            ->assertHeader('Access-Control-Allow-Origin', 'http://127.0.0.1:5173');
        $this->getJson('/api/v1/events', ['Origin' => WEB])
            ->assertHeader('Access-Control-Allow-Origin', WEB);
        preflight('http://127.0.0.1:5173')->assertHeader('Access-Control-Allow-Origin', 'http://127.0.0.1:5173');
        $this->getJson('/api/v1/events', ['Origin' => EVIL])->assertHeaderMissing('Access-Control-Allow-Origin');
    });
});

it('matches origins exactly, not as a prefix or with the dot as a wildcard', function (string $origin): void {
    withWebOrigin('http://web.test', function () use ($origin): void {
        $this->getJson('/api/v1/events', ['Origin' => $origin])->assertHeaderMissing('Access-Control-Allow-Origin');
    });
})->with(['http://webxtest', 'http://web.test.evil.test', 'https://web.test', 'http://evil.test/http://web.test']);

it('allows no origin when WEB_ORIGIN is empty', function (): void {
    withWebOrigin('', function (): void {
        $this->getJson('/api/v1/events', ['Origin' => WEB])->assertHeaderMissing('Access-Control-Allow-Origin');
    });
});

it('does not allow a request header outside the client list', function (): void {
    $response = preflight(WEB, 'GET', 'authorization, x-evil');

    expect(strtolower((string) $response->headers->get('Access-Control-Allow-Headers')))->not->toContain('x-evil');
});

it('gives no Allow-Origin outside the api paths, even to the web origin', function (): void {
    $this->get('/health/live', ['Origin' => WEB])->assertOk()->assertHeaderMissing('Access-Control-Allow-Origin');
});
