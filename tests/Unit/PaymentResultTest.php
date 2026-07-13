<?php

declare(strict_types=1);

use App\Domain\Order\DTO\PaymentResult;

it('can only be built through its two named constructors, so no third state exists', function (): void {
    $result = PaymentResult::approved(5000, 'EUR');

    expect((new ReflectionMethod(PaymentResult::class, '__construct'))->isPrivate())->toBeTrue()
        ->and((new ReflectionClass(PaymentResult::class))->isFinal())->toBeTrue()
        ->and(fn (): mixed => $result->approved = false)->toThrow(Error::class)
        ->and(fn (): mixed => $result->amount_cents = 1)->toThrow(Error::class);
});

it('carries the outcome and the money each named constructor was given', function (): void {
    $approved = PaymentResult::approved(5000, 'EUR');
    $declined = PaymentResult::declined(1234, 'USD');

    expect($approved->approved)->toBeTrue()
        ->and($approved->amount_cents)->toBe(5000)
        ->and($approved->currency)->toBe('EUR')
        ->and($declined->approved)->toBeFalse()
        ->and($declined->amount_cents)->toBe(1234)
        ->and($declined->currency)->toBe('USD');
});
