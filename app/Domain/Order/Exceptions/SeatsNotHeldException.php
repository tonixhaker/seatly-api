<?php

declare(strict_types=1);

namespace App\Domain\Order\Exceptions;

use App\Domain\Shared\Enums\ErrorCode;
use App\Domain\Shared\Exceptions\DomainException;

final class SeatsNotHeldException extends DomainException
{
    public function errorCode(): ErrorCode
    {
        return ErrorCode::SEATS_NOT_HELD;
    }
}
