<?php

declare(strict_types=1);

namespace App\Domain\Order\Exceptions;

use App\Domain\Shared\Enums\ErrorCode;
use App\Domain\Shared\Exceptions\DomainException;

final class DuplicateIdempotencyKeyException extends DomainException
{
    public function errorCode(): ErrorCode
    {
        return ErrorCode::VALIDATION_FAILED;
    }
}
