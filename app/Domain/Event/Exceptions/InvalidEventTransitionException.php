<?php

declare(strict_types=1);

namespace App\Domain\Event\Exceptions;

use App\Domain\Shared\Enums\ErrorCode;
use App\Domain\Shared\Exceptions\DomainException;

final class InvalidEventTransitionException extends DomainException
{
    public function errorCode(): ErrorCode
    {
        return ErrorCode::INVALID_STATE_TRANSITION;
    }
}
