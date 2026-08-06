<?php

declare(strict_types=1);

use App\Domain\Order\Exceptions\HoldsValidationUnavailableException;
use App\Domain\Shared\Enums\ErrorCode;
use App\Infrastructure\Realtime\HttpHoldsValidator;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Context\Repository;
use Illuminate\Support\Facades\Context;
use Psr\Log\AbstractLogger;

beforeEach(function (): void {
    Context::swap(new Repository(new Dispatcher));
});

afterEach(function (): void {
    Context::clearResolvedInstances();
});

$spyLogger = static fn (): object => new class extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string|Stringable, context: array<mixed>}> */
    public array $records = [];

    /**
     * @param  array<mixed>  $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => $message, 'context' => $context];
    }
};

$makeValidator = static function (Factory $http, object $logger, string $baseUrl = 'http://realtime.test', string $token = 'shared-secret-token', float $timeout = 2.0) use (&$makeValidator): HttpHoldsValidator {
    return new HttpHoldsValidator($http, $baseUrl, $token, $timeout, $logger);
};

$fakeReturning = static function (mixed $body, int $status = 200): Factory {
    $http = new Factory;
    $http->fake(['*' => Factory::response($body, $status)]);

    return $http;
};

it('emits the query as repeated seat_ids keys, never bracket-indexed', function () use ($spyLogger, $makeValidator, $fakeReturning): void {
    $http = $fakeReturning(['valid' => false, 'missing' => [4, 7]]);

    $makeValidator($http, $spyLogger())->missingSeats(1, [4, 7], '11111111-2222-4333-8444-555555555555');

    $urls = [];
    $http->recorded(function ($request) use (&$urls): bool {
        $urls[] = $request->url();

        return true;
    });

    expect($urls[0])->toBe('http://realtime.test/internal/holds/validate?event_id=1&seat_ids=4&seat_ids=7&session_id=11111111-2222-4333-8444-555555555555');
});

it('emits exactly one seat_ids key for a single seat and no bracket', function () use ($spyLogger, $makeValidator, $fakeReturning): void {
    $http = $fakeReturning(['valid' => true, 'missing' => []]);

    $makeValidator($http, $spyLogger())->missingSeats(9, [42], 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee');

    $url = '';
    $http->recorded(function ($request) use (&$url): bool {
        $url = $request->url();

        return true;
    });

    expect(substr_count($url, 'seat_ids='))->toBe(1)
        ->and($url)->not->toContain('[')
        ->and($url)->not->toContain('%5B');
});

it('sends the token as a header and never puts it in the url', function () use ($spyLogger, $makeValidator, $fakeReturning): void {
    $http = $fakeReturning(['valid' => true, 'missing' => []]);

    $makeValidator($http, $spyLogger())->missingSeats(1, [1], 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee');

    $header = '';
    $url = '';
    $http->recorded(function ($request) use (&$header, &$url): bool {
        $header = $request->header('X-Internal-Token')[0] ?? '';
        $url = $request->url();

        return true;
    });

    expect($header)->toBe('shared-secret-token')
        ->and($url)->not->toContain('shared-secret-token');
});

it('forwards the request id in scope next to the internal token', function () use ($spyLogger, $makeValidator, $fakeReturning): void {
    $requestId = '3f2b8c1e-5d4a-4e6f-9a7b-1c2d3e4f5a6b';
    Context::add('request_id', $requestId);
    $http = $fakeReturning(['valid' => true, 'missing' => []]);

    $makeValidator($http, $spyLogger())->missingSeats(1, [1], 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee');

    $http->assertSentCount(1);
    $http->assertSent(fn (Request $request): bool => $request->header('X-Request-Id') === [$requestId]
        && $request->header('X-Internal-Token') === ['shared-secret-token']);
});

it('sends no request id header when no request id is in scope', function () use ($spyLogger, $makeValidator, $fakeReturning): void {
    $http = $fakeReturning(['valid' => true, 'missing' => []]);

    expect($makeValidator($http, $spyLogger())->missingSeats(1, [1], 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee'))->toBe([]);

    $http->assertSentCount(1);
    $http->assertSent(fn (Request $request): bool => ! $request->hasHeader('X-Request-Id')
        && $request->header('X-Internal-Token') === ['shared-secret-token']);
});

it('returns the missing list verbatim without sorting or deduping', function () use ($spyLogger, $makeValidator, $fakeReturning): void {
    $http = $fakeReturning(['valid' => false, 'missing' => [7, 3]]);

    expect($makeValidator($http, $spyLogger())->missingSeats(1, [3, 7], 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee'))->toBe([7, 3]);
});

it('returns an empty list when the session holds every seat', function () use ($spyLogger, $makeValidator, $fakeReturning): void {
    $http = $fakeReturning(['valid' => true, 'missing' => []]);
    $logger = $spyLogger();

    expect($makeValidator($http, $logger)->missingSeats(1, [1, 2], 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee'))->toBe([])
        ->and($logger->records)->toBe([]);
});

it('reads missing, not valid, so a contradictory body fails closed', function () use ($spyLogger, $makeValidator, $fakeReturning): void {
    $http = $fakeReturning(['valid' => true, 'missing' => [3]]);

    expect($makeValidator($http, $spyLogger())->missingSeats(1, [3], 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee'))->toBe([3]);
});

it('refuses a body claiming invalid while naming no missing seat', function () use ($spyLogger, $makeValidator, $fakeReturning): void {
    $http = $fakeReturning(['valid' => false, 'missing' => []]);
    $logger = $spyLogger();

    expect(fn () => $makeValidator($http, $logger)->missingSeats(1, [1], 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee'))
        ->toThrow(HoldsValidationUnavailableException::class);

    expect($logger->records[0]['context']['reason'])->toBe('malformed_body');
});

it('turns a 401 into the unavailable exception, logged at error, without the token', function () use ($spyLogger, $makeValidator, $fakeReturning): void {
    $http = $fakeReturning(['error' => ['code' => 'UNAUTHENTICATED']], 401);
    $logger = $spyLogger();

    expect(fn () => $makeValidator($http, $logger)->missingSeats(1, [1], 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee'))
        ->toThrow(HoldsValidationUnavailableException::class);

    expect($logger->records)->toHaveCount(1)
        ->and($logger->records[0]['level'])->toBe('error')
        ->and($logger->records[0]['context']['reason'])->toBe('unauthorized')
        ->and($logger->records[0]['context']['status'])->toBe(401)
        ->and(json_encode($logger->records))->not->toContain('shared-secret-token');
});

it('turns a connection failure into the unavailable exception, logged at warning', function () use ($spyLogger, $makeValidator): void {
    $http = new Factory;
    $http->fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect'));
    $logger = $spyLogger();

    expect(fn () => $makeValidator($http, $logger)->missingSeats(1, [1], 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee'))
        ->toThrow(HoldsValidationUnavailableException::class);

    expect($logger->records)->toHaveCount(1)
        ->and($logger->records[0]['level'])->toBe('warning')
        ->and($logger->records[0]['context']['reason'])->toBe('unreachable');
});

it('keeps the request url, and so the session id, out of the outage log', function () use ($spyLogger, $makeValidator): void {
    $sessionId = '9e107d9d-372b-4144-a0f0-3f7b9a1e2c4d';
    $http = new Factory;
    $http->fake(fn (): never => throw new ConnectionException(
        'cURL error 6: Could not resolve host: realtime (see https://curl.se/x) for http://realtime.test/internal/holds/validate?event_id=1&seat_ids=1&session_id='.$sessionId
    ));
    $logger = $spyLogger();

    expect(fn () => $makeValidator($http, $logger)->missingSeats(1, [1], $sessionId))
        ->toThrow(HoldsValidationUnavailableException::class);

    $serialised = json_encode($logger->records, JSON_THROW_ON_ERROR);

    expect($serialised)->not->toContain($sessionId)
        ->and($serialised)->not->toContain('/internal/holds/validate')
        ->and($logger->records[0]['context']['detail'])->toContain('cURL error 6');
});

it('turns any other non-200 into the unavailable exception', function (int $status) use ($spyLogger, $makeValidator, $fakeReturning): void {
    $http = $fakeReturning(['error' => ['code' => 'VALIDATION_FAILED']], $status);
    $logger = $spyLogger();

    expect(fn () => $makeValidator($http, $logger)->missingSeats(1, [1], 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee'))
        ->toThrow(HoldsValidationUnavailableException::class);

    expect($logger->records[0]['level'])->toBe('error')
        ->and($logger->records[0]['context']['reason'])->toBe('unexpected_status')
        ->and($logger->records[0]['context']['status'])->toBe($status);
})->with([400, 404, 500, 502]);

it('refuses a malformed 200 body instead of reporting nothing missing', function (mixed $body) use ($spyLogger, $makeValidator, $fakeReturning): void {
    $http = $fakeReturning($body);
    $logger = $spyLogger();

    expect(fn () => $makeValidator($http, $logger)->missingSeats(1, [1], 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee'))
        ->toThrow(HoldsValidationUnavailableException::class);

    expect($logger->records[0]['context']['reason'])->toBe('malformed_body');
})->with([
    'no missing key' => [['valid' => true]],
    'missing is an object' => [['valid' => false, 'missing' => ['a' => 1]]],
    'missing holds strings' => [['valid' => false, 'missing' => ['3']]],
    'missing is a string' => [['valid' => false, 'missing' => 'nope']],
]);

it('fails without sending a request when the base url is unconfigured', function () use ($spyLogger, $makeValidator): void {
    $http = new Factory;
    $http->fake(['*' => Factory::response(['valid' => true, 'missing' => []], 200)]);
    $logger = $spyLogger();

    expect(fn () => $makeValidator($http, $logger, '')->missingSeats(1, [1], 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee'))
        ->toThrow(HoldsValidationUnavailableException::class);

    $sent = 0;
    $http->recorded(function () use (&$sent): bool {
        $sent++;

        return true;
    });

    expect($sent)->toBe(0)
        ->and($logger->records[0]['level'])->toBe('error')
        ->and($logger->records[0]['context']['reason'])->toBe('unconfigured');
});

it('honours the configured timeout against a responder that never answers', function () use ($spyLogger, $makeValidator): void {
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

    expect($server)->not->toBeFalse();

    $name = stream_socket_get_name($server, false);
    $logger = $spyLogger();
    $validator = $makeValidator(new Factory, $logger, 'http://'.$name, 'shared-secret-token', 0.5);

    $started = microtime(true);

    expect(fn () => $validator->missingSeats(1, [1], 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee'))
        ->toThrow(HoldsValidationUnavailableException::class);

    $elapsed = microtime(true) - $started;

    fclose($server);

    expect($elapsed)->toBeGreaterThanOrEqual(0.4)
        ->and($elapsed)->toBeLessThan(2.0)
        ->and($logger->records[0]['context']['reason'])->toBe('unreachable');
});

it('maps the unavailable exception to SERVICE_UNAVAILABLE and 503', function (): void {
    $exception = new HoldsValidationUnavailableException('nope');

    expect($exception->errorCode())->toBe(ErrorCode::SERVICE_UNAVAILABLE)
        ->and($exception->errorCode()->status())->toBe(503);
});
