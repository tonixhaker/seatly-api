<?php

declare(strict_types=1);

use App\Infrastructure\Payments\FakePaymentGateway;
use Random\Engine;
use Random\Engine\Mt19937;
use Random\Randomizer;

$constantEngine = function (string $byte): Randomizer {
    return new Randomizer(new class($byte) implements Engine
    {
        public function __construct(private readonly string $byte) {}

        public function generate(): string
        {
            return str_repeat($this->byte, 8);
        }
    });
};

$instant = function (Randomizer $randomizer, float $declineRate = 0.15): FakePaymentGateway {
    return new FakePaymentGateway($randomizer, 0, 0, $declineRate);
};

it('declines when the injected randomness forces it, with no reliance on chance', function () use ($constantEngine, $instant): void {
    $result = $instant($constantEngine("\x00"))->charge(5000, 'EUR');

    expect($result->approved)->toBeFalse();
});

it('approves when the injected randomness forces it, with no reliance on chance', function () use ($constantEngine, $instant): void {
    $result = $instant($constantEngine("\xff"))->charge(5000, 'EUR');

    expect($result->approved)->toBeTrue();
});

it('echoes back the amount and currency it was asked to charge', function (int $amountCents, string $currency, string $byte, bool $approved) use ($constantEngine, $instant): void {
    $result = $instant($constantEngine($byte))->charge($amountCents, $currency);

    expect($result->amount_cents)->toBe($amountCents)
        ->and($result->currency)->toBe($currency)
        ->and($result->approved)->toBe($approved);
})->with([
    'approved euros' => [5000, 'EUR', "\xff", true],
    'declined dollars' => [1234, 'USD', "\x00", false],
    'approved dollars' => [1234, 'USD', "\xff", true],
]);

it('refuses a charge that names no money, before drawing anything', function (int $amountCents, string $currency) use ($constantEngine, $instant): void {
    $gateway = $instant($constantEngine("\x00"));

    expect(fn (): mixed => $gateway->charge($amountCents, $currency))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'zero amount' => [0, 'EUR'],
    'negative amount' => [-1, 'EUR'],
    'no currency' => [5000, ''],
]);

it('refuses a delay range that is negative or inverted, and a rate that is not a probability', function (int $min, int $max, float $rate): void {
    expect(fn (): FakePaymentGateway => new FakePaymentGateway(new Randomizer(new Mt19937(1)), $min, $max, $rate))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'negative minimum' => [-1, 100, 0.15],
    'inverted range' => [200, 100, 0.15],
    'rate above one' => [0, 0, 1.5],
    'rate below zero' => [0, 0, -0.1],
]);

it('declines within a stated tolerance of fifteen percent over a large seeded sample', function () use ($instant): void {
    $gateway = $instant(new Randomizer(new Mt19937(20260916)));

    $declines = 0;

    for ($i = 0; $i < 10000; $i++) {
        if (! $gateway->charge(5000, 'EUR')->approved) {
            $declines++;
        }
    }

    expect($declines)->toBe(1495)
        ->and($declines / 10000)->toBeGreaterThan(0.14)
        ->and($declines / 10000)->toBeLessThan(0.16);
});

it('spends real time inside a charge, at least the floor of its configured range', function () use ($constantEngine): void {
    $gateway = new FakePaymentGateway($constantEngine("\x00"), 20, 30, 1.0);

    $before = hrtime(true);
    $gateway->charge(5000, 'EUR');
    $elapsedMs = (hrtime(true) - $before) / 1_000_000;

    expect($elapsedMs)->toBeGreaterThan(18.0);
});

it('draws the delay from the range rather than pinning it to one end', function () use ($constantEngine): void {
    $gateway = new FakePaymentGateway($constantEngine("\xff"), 20, 30, 0.0);

    $before = hrtime(true);
    $gateway->charge(5000, 'EUR');
    $elapsedMs = (hrtime(true) - $before) / 1_000_000;

    expect($elapsedMs)->toBeGreaterThan(28.0);
});
