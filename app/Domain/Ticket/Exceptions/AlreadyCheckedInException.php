<?php

declare(strict_types=1);

namespace App\Domain\Ticket\Exceptions;

use App\Domain\Shared\Enums\ErrorCode;
use App\Domain\Shared\Exceptions\DomainException;

final class AlreadyCheckedInException extends DomainException
{
    public function errorCode(): ErrorCode
    {
        return ErrorCode::ALREADY_CHECKED_IN;
    }
}
