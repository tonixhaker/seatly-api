<?php

declare(strict_types=1);

use App\Domain\Order\Contracts\HoldsValidatorInterface;
use App\Domain\Order\Exceptions\HoldsValidationUnavailableException;
use App\Infrastructure\Realtime\HttpHoldsValidator;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

$realtimeUrl = function (): string {
    $url = getenv('REALTIME_TEST_URL');

    if (! is_string($url) || $url === '') {
        test()->markTestSkipped('REALTIME_TEST_URL is not set; the holds integration test needs a live seatly-realtime.');
    }

    return rtrim($url, '/');
};

$internalToken = function (): string {
    $token = getenv('INTERNAL_TOKEN');

    return is_string($token) && $token !== '' ? $token : 'local-internal-token';
};

$validator = function (string $url, string $token) use ($internalToken): HoldsValidatorInterface {
    return new HttpHoldsValidator(new Factory, $url, $token === '' ? $internalToken() : $token, 2.0, Log::getLogger());
};

$hold = function (string $url, int $eventId, array $seatIds, string $sessionId): void {
    $response = (new Factory)->acceptJson()->post($url.'/holds', [
        'event_id' => $eventId,
        'seat_ids' => $seatIds,
        'session_id' => $sessionId,
    ]);

    expect($response->status())->toBe(201);
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
        expect($validator($url, '')->missingSeats($eventId, [11, 12], $sessionId))->toBe([]);
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
        expect($validator($url, '')->missingSeats($eventId, [23, 22, 24], $sessionId))->toBe([23, 24]);
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
        expect($validator($url, '')->missingSeats($eventId, [31], $stranger))->toBe([31])
            ->and($validator($url, '')->missingSeats($eventId, [31], $owner))->toBe([]);
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
        expect($validator($url, '')->missingSeats($otherEvent, [41], $sessionId))->toBe([41])
            ->and($validator($url, '')->missingSeats($heldEvent, [41], $sessionId))->toBe([]);
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
        expect($validator($url, '')->missingSeats($eventId, [51], $sessionId))->toBe([]);
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
        expect(fn () => $validator($url, 'a-wrong-internal-token')->missingSeats($eventId, [61], $sessionId))
            ->toThrow(HoldsValidationUnavailableException::class);
    } finally {
        $release($url, $eventId, [61], $sessionId);
    }
});
