<?php

declare(strict_types=1);

namespace App\Domain\Order\Exceptions;

use App\Domain\Shared\Enums\ErrorCode;
use App\Domain\Shared\Exceptions\DomainException;

final class PaymentDeclinedException extends DomainException
{
    public function errorCode(): ErrorCode
    {
        return ErrorCode::PAYMENT_DECLINED;
    }
}
