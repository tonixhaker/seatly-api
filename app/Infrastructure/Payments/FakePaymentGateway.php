<?php

declare(strict_types=1);

namespace App\Infrastructure\Payments;

use App\Domain\Order\Contracts\PaymentGatewayInterface;
use App\Domain\Order\DTO\PaymentResult;
use InvalidArgumentException;
use Random\Randomizer;

final readonly class FakePaymentGateway implements PaymentGatewayInterface
{
    public function __construct(
        private Randomizer $randomizer,
        private int $delayMinMs,
        private int $delayMaxMs,
        private float $declineRate,
    ) {
        if ($delayMinMs < 0 || $delayMaxMs < $delayMinMs) {
            throw new InvalidArgumentException('The delay range must be non-negative and ordered.');
        }

        if ($declineRate < 0.0 || $declineRate > 1.0) {
            throw new InvalidArgumentException('The decline rate must be a probability.');
        }
    }

    public function charge(int $amountCents, string $currency): PaymentResult
    {
        if ($amountCents <= 0) {
            throw new InvalidArgumentException('A charge must be for a positive amount.');
        }

        if ($currency === '') {
            throw new InvalidArgumentException('A charge must name a currency.');
        }

        $this->pause();

        return $this->randomizer->nextFloat() < $this->declineRate
            ? PaymentResult::declined($amountCents, $currency)
            : PaymentResult::approved($amountCents, $currency);
    }

    private function pause(): void
    {
        $span = $this->delayMaxMs - $this->delayMinMs;
        $milliseconds = $span === 0
            ? $this->delayMinMs
            : $this->delayMinMs + (int) round($span * $this->randomizer->nextFloat());

        if ($milliseconds > 0) {
            usleep($milliseconds * 1000);
        }
    }
}
