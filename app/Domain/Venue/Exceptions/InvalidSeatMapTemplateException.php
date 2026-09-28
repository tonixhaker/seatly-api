<?php

declare(strict_types=1);

namespace App\Domain\Venue\Exceptions;

use App\Domain\Shared\Enums\ErrorCode;
use App\Domain\Shared\Exceptions\DomainException;

final class InvalidSeatMapTemplateException extends DomainException
{
    public function errorCode(): ErrorCode
    {
        return ErrorCode::INVALID_SEAT_MAP_TEMPLATE;
    }
}
