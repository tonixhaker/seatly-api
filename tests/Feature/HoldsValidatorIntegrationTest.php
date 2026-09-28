<?php

declare(strict_types=1);

use App\Domain\Event\Events\EventPublished;
use App\Domain\Order\Contracts\HoldsValidatorInterface;
use App\Domain\Order\Exceptions\HoldsValidationUnavailableException;
use App\Domain\Shared\Contracts\EventPublisherInterface;
use App\Infrastructure\Messaging\RabbitMqEventPublisher;
use App\Infrastructure\Realtime\HttpHoldsValidator;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

$internalToken = function (): string {
    $token = getenv('REALTIME_TEST_TOKEN');

    if (! is_string($token) || $token === '') {
        test()->markTestSkipped('REALTIME_TEST_TOKEN is not set; the holds integration test needs the INTERNAL_TOKEN of that seatly-realtime.');
    }

    return $token;
};

$realtimeUrl = function () use ($internalToken): string {
    $url = getenv('REALTIME_TEST_URL');

    if (! is_string($url) || $url === '') {
        test()->markTestSkipped('REALTIME_TEST_URL is not set; the holds integration test needs a live seatly-realtime.');
    }

    $internalToken();

    return rtrim($url, '/');
};

$internalUrl = function (): string {
    $url = getenv('REALTIME_TEST_INTERNAL_URL');

    if (! is_string($url) || $url === '') {
        test()->markTestSkipped('REALTIME_TEST_INTERNAL_URL is not set; the holds integration test needs the INTERNAL_PORT address of that seatly-realtime.');
    }

    return rtrim($url, '/');
};

$validator = function (string $token) use ($internalToken, $internalUrl): HoldsValidatorInterface {
    return new HttpHoldsValidator(new Factory, $internalUrl(), $token === '' ? $internalToken() : $token, 2.0, Log::getLogger());
};

$broker = function () use ($internalUrl): EventPublisherInterface {
    $internalUrl();
    $url = getenv('RABBITMQ_TEST_URL');

    if (! is_string($url) || $url === '') {
        test()->fail('RABBITMQ_TEST_URL is not set; the holds integration test publishes event.published to the broker that seatly-realtime consumes so realtime knows the seats it is asked to hold.');
    }

    config(['messaging.rabbitmq.url' => $url]);
    app()->forgetInstance(EventPublisherInterface::class);
    $publisher = app(EventPublisherInterface::class);

    expect($publisher)->toBeInstanceOf(RabbitMqEventPublisher::class);

    return $publisher;
};

$hold = function (string $url, int $eventId, array $seatIds, string $sessionId) use ($broker): void {
    $publisher = $broker();

    for ($attempt = 0; $attempt < 100; $attempt++) {
        $publisher->publish(new EventPublished($eventId, $seatIds));

        $response = (new Factory)->acceptJson()->post($url.'/holds', [
            'event_id' => $eventId,
            'seat_ids' => $seatIds,
            'session_id' => $sessionId,
        ]);

        if ($response->status() === 201) {
            break;
        }

        usleep(100_000);
    }

    expect($response->status())->toBe(201, "POST /holds for event {$eventId} never answered 201 after publishing event.published; last: {$response->status()} {$response->body()}");
};

$release = function (string $url, int $eventId, array $seatIds, string $sessionId): void {
    (new Factory)->acceptJson()->send('DELETE', $url.'/holds', [
        'json' => ['event_id' => $eventId, 'seat_ids' => $seatIds, 'session_id' => $sessionId],
    ]);
};

$session = fn (): string => (string) Str::uuid();

it('reports nothing missing for a hold really taken through POST /holds', function () use ($realtimeUrl, $validator, $hold, $release, $session): void {
    $url = $realtimeUrl();
    $eventId = 910001;
    $sessionId = $session();

    $hold($url, $eventId, [11, 12], $sessionId);

    try {
        expect($validator('')->missingSeats($eventId, [11, 12], $sessionId))->toBe([]);
    } finally {
        $release($url, $eventId, [11, 12], $sessionId);
    }
});

it('names exactly the subset the session does not hold, in request order', function () use ($realtimeUrl, $validator, $hold, $release, $session): void {
    $url = $realtimeUrl();
    $eventId = 910002;
    $sessionId = $session();

    $hold($url, $eventId, [21, 22], $sessionId);

    try {
        expect($validator('')->missingSeats($eventId, [23, 22, 24], $sessionId))->toBe([23, 24]);
    } finally {
        $release($url, $eventId, [21, 22], $sessionId);
    }
});

it('counts a seat held by a different session as missing', function () use ($realtimeUrl, $validator, $hold, $release, $session): void {
    $url = $realtimeUrl();
    $eventId = 910003;
    $owner = $session();
    $stranger = $session();

    $hold($url, $eventId, [31], $owner);

    try {
        expect($validator('')->missingSeats($eventId, [31], $stranger))->toBe([31])
            ->and($validator('')->missingSeats($eventId, [31], $owner))->toBe([]);
    } finally {
        $release($url, $eventId, [31], $owner);
    }
});

it('counts a seat held at a different event as missing, same session and same seat id', function () use ($realtimeUrl, $validator, $hold, $release, $session): void {
    $url = $realtimeUrl();
    $heldEvent = 910004;
    $otherEvent = 910005;
    $sessionId = $session();

    $hold($url, $heldEvent, [41], $sessionId);

    try {
        expect($validator('')->missingSeats($otherEvent, [41], $sessionId))->toBe([41])
            ->and($validator('')->missingSeats($heldEvent, [41], $sessionId))->toBe([]);
    } finally {
        $release($url, $heldEvent, [41], $sessionId);
    }
});

it('validates a single seat, the shape the query encoding breaks first', function () use ($realtimeUrl, $validator, $hold, $release, $session): void {
    $url = $realtimeUrl();
    $eventId = 910006;
    $sessionId = $session();

    $hold($url, $eventId, [51], $sessionId);

    try {
        expect($validator('')->missingSeats($eventId, [51], $sessionId))->toBe([]);
    } finally {
        $release($url, $eventId, [51], $sessionId);
    }
});

it('refuses to answer when the shared token is wrong, rather than reporting nothing missing', function () use ($realtimeUrl, $validator, $hold, $release, $session): void {
    $url = $realtimeUrl();
    $eventId = 910007;
    $sessionId = $session();

    $hold($url, $eventId, [61], $sessionId);

    try {
        expect(fn () => $validator('a-wrong-internal-token')->missingSeats($eventId, [61], $sessionId))
            ->toThrow(HoldsValidationUnavailableException::class);
    } finally {
        $release($url, $eventId, [61], $sessionId);
    }
});
