<?php

declare(strict_types=1);

namespace App\Domain\Order\Contracts;

use App\Domain\Order\DTO\PaymentResult;

interface PaymentGatewayInterface
{
    public function charge(int $amountCents, string $currency): PaymentResult;
}
