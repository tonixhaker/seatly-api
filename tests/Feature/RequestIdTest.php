<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\ErrorCode;
use App\Domain\Shared\Exceptions\DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\LogRecord;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->knownId = '2f1c8e1a-3b4d-4c5e-9f60-7a8b9c0d1e2f';

    config(['logging.default' => 'null']);
    $this->logs = new TestHandler;
    Log::driver()->getLogger()->pushHandler($this->logs);

    $this->probes = fn (): array => array_values(array_filter(
        $this->logs->getRecords(),
        fn (LogRecord $record): bool => $record->message === 'probe',
    ));
    $this->listenProbe = fn () => Event::listen(RouteMatched::class, fn () => Log::info('probe'));
});

it('echoes a well-formed X-Request-Id and stamps it on log lines of the request', function (): void {
    ($this->listenProbe)();

    $this->getJson('/api/v1/events', ['X-Request-Id' => $this->knownId])
        ->assertOk()
        ->assertHeader('X-Request-Id', $this->knownId);

    $probes = ($this->probes)();

    expect($probes)->toHaveCount(1)
        ->and($probes[0]->extra['request_id'] ?? null)->toBe($this->knownId);
});

it('generates a UUID when no X-Request-Id is sent and stamps that same id on log lines', function (): void {
    ($this->listenProbe)();

    $id = $this->getJson('/api/v1/events')->assertOk()->headers->get('X-Request-Id');

    $probes = ($this->probes)();

    expect(Str::isUuid((string) $id))->toBeTrue()
        ->and($probes)->toHaveCount(1)
        ->and($probes[0]->extra['request_id'] ?? null)->toBe($id);
});

it('gives two header-less requests different ids and keeps each id on its own log lines', function (): void {
    ($this->listenProbe)();

    $first = $this->getJson('/api/v1/events')->headers->get('X-Request-Id');
    $firstIds = array_map(fn (LogRecord $record): mixed => $record->extra['request_id'] ?? null, $this->logs->getRecords());
    $this->logs->clear();

    $second = $this->getJson('/api/v1/events')->headers->get('X-Request-Id');
    $secondIds = array_map(fn (LogRecord $record): mixed => $record->extra['request_id'] ?? null, $this->logs->getRecords());

    expect(Str::isUuid((string) $first))->toBeTrue()
        ->and(Str::isUuid((string) $second))->toBeTrue()
        ->and($first)->not->toBe($second)
        ->and($firstIds)->not->toBeEmpty()
        ->and(array_unique($firstIds))->toBe([$first])
        ->and($secondIds)->not->toBeEmpty()
        ->and(array_unique($secondIds))->toBe([$second]);
});

it('does not carry a supplied id over to the next header-less request', function (): void {
    ($this->listenProbe)();

    $this->getJson('/api/v1/events', ['X-Request-Id' => $this->knownId])->assertHeader('X-Request-Id', $this->knownId);
    $this->logs->clear();

    $next = $this->getJson('/api/v1/events')->headers->get('X-Request-Id');
    $probes = ($this->probes)();

    expect(Str::isUuid((string) $next))->toBeTrue()
        ->and($next)->not->toBe($this->knownId)
        ->and($probes)->toHaveCount(1)
        ->and($probes[0]->extra['request_id'] ?? null)->toBe($next);
});

it('replaces a malformed X-Request-Id with a fresh UUID and never logs the supplied value', function (string $sent): void {
    ($this->listenProbe)();

    $id = $this->getJson('/api/v1/events', ['X-Request-Id' => $sent])->assertOk()->headers->get('X-Request-Id');

    $probes = ($this->probes)();
    $logged = json_encode(array_map(fn (LogRecord $record): array => $record->toArray(), $this->logs->getRecords()), JSON_THROW_ON_ERROR);
    $beforeBreak = (string) preg_split('/[\r\n]/', $sent)[0];

    expect(Str::isUuid((string) $id))->toBeTrue()
        ->and($id)->not->toBe($sent)
        ->and($id)->not->toBe($beforeBreak)
        ->and($probes)->toHaveCount(1)
        ->and($probes[0]->extra['request_id'] ?? null)->toBe($id)
        ->and($logged)->not->toContain($beforeBreak);
})->with([
    'not a uuid' => ['not-a-uuid'],
    'oversized' => [str_repeat('a', 5000)],
    'header injection' => ['7d3b0a52-1c9e-4f7a-8b21-6e5d4c3b2a19'."\r\nX-Injected: 1"],
    'trailing newline' => ['9a8b7c6d-5e4f-4a3b-8c2d-1e0f9a8b7c6d'."\n"],
]);

it('logs a 404 envelope once at warning with the request id and leaves the envelope unchanged', function (): void {
    $this->getJson('/api/v1/events/999999999', ['X-Request-Id' => $this->knownId])
        ->assertStatus(404)
        ->assertHeader('X-Request-Id', $this->knownId)
        ->assertExactJson(['error' => ['code' => 'NOT_FOUND', 'message' => 'The requested resource was not found.']]);

    $records = $this->logs->getRecords();

    expect($records)->toHaveCount(1)
        ->and($records[0]->level)->toBe(Level::Warning)
        ->and($records[0]->extra['request_id'] ?? null)->toBe($this->knownId)
        ->and($records[0]->context)->toBe([
            'method' => 'GET',
            'path' => 'api/v1/events/999999999',
            'status' => 404,
            'code' => 'NOT_FOUND',
        ]);
});

it('logs a forced 500 once at error with the request id and the exception', function (): void {
    Route::get('api/__request-id/boom', fn () => throw new RuntimeException('boom'));

    $this->getJson('/api/__request-id/boom', ['X-Request-Id' => $this->knownId])
        ->assertStatus(500)
        ->assertHeader('X-Request-Id', $this->knownId)
        ->assertExactJson(['error' => ['code' => 'INTERNAL_ERROR', 'message' => 'An unexpected error occurred.']]);

    $records = $this->logs->getRecords();

    expect($records)->toHaveCount(1)
        ->and($records[0]->level)->toBe(Level::Error)
        ->and($records[0]->extra['request_id'] ?? null)->toBe($this->knownId)
        ->and($records[0]->context['exception'] ?? null)->toBeInstanceOf(RuntimeException::class);
});

it('logs a domain 4xx exactly once at warning with the request id', function (): void {
    Route::get('api/__request-id/domain', fn () => throw new class('This ticket was already checked in.') extends DomainException
    {
        public function errorCode(): ErrorCode
        {
            return ErrorCode::ALREADY_CHECKED_IN;
        }
    });

    $this->getJson('/api/__request-id/domain', ['X-Request-Id' => $this->knownId])
        ->assertStatus(409)
        ->assertHeader('X-Request-Id', $this->knownId)
        ->assertJsonPath('error.code', 'ALREADY_CHECKED_IN');

    $records = $this->logs->getRecords();

    expect($records)->toHaveCount(1)
        ->and($records[0]->level)->toBe(Level::Warning)
        ->and($records[0]->extra['request_id'] ?? null)->toBe($this->knownId);
});

it('sets a UUID X-Request-Id on both health probes', function (string $path): void {
    $id = $this->get($path, ['Accept' => ''])->assertOk()->headers->get('X-Request-Id');

    expect(Str::isUuid((string) $id))->toBeTrue();
})->with(['/health/live', '/health']);

it('sets X-Request-Id on the maintenance mode 503', function (): void {
    config(['app.maintenance.driver' => 'cache', 'app.maintenance.store' => 'array']);
    $this->app->maintenanceMode()->activate([]);

    $response = $this->getJson('/api/v1/events', ['X-Request-Id' => $this->knownId]);

    $this->app->maintenanceMode()->deactivate();

    $response->assertStatus(503)
        ->assertJsonPath('error.code', 'SERVICE_UNAVAILABLE')
        ->assertHeader('X-Request-Id', $this->knownId);
});

it('writes one JSON line per record on the stderr channel with the request id and a stack trace', function (): void {
    $path = (string) tempnam(sys_get_temp_dir(), 'request-id-');

    config([
        'logging.channels.stderr.handler_with.stream' => $path,
        'logging.channels.stderr.formatter' => JsonFormatter::class,
    ]);
    Log::forgetChannel('stderr');
    Context::add('request_id', $this->knownId);

    Log::channel('stderr')->error('x', ['exception' => new RuntimeException('boom')]);

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    unlink($path);

    expect($lines)->toHaveCount(1);

    $line = json_decode((string) $lines[0], true, 512, JSON_THROW_ON_ERROR);

    expect($line['extra']['request_id'] ?? null)->toBe($this->knownId)
        ->and($line['context']['exception']['trace'] ?? null)->not->toBeEmpty();
});
