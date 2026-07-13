<?php

declare(strict_types=1);

namespace App\Domain\Order\DTO;

final readonly class PaymentResult
{
    private function __construct(
        public bool $approved,
        public int $amount_cents,
        public string $currency,
    ) {}

    public static function approved(int $amountCents, string $currency): self
    {
        return new self(true, $amountCents, $currency);
    }

    public static function declined(int $amountCents, string $currency): self
    {
        return new self(false, $amountCents, $currency);
    }
}
