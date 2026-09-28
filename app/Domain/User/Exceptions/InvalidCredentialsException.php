<?php

declare(strict_types=1);

namespace App\Domain\User\Exceptions;

use App\Domain\Shared\Enums\ErrorCode;
use App\Domain\Shared\Exceptions\DomainException;

final class InvalidCredentialsException extends DomainException
{
    public function __construct()
    {
        parent::__construct('These credentials do not match our records.');
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::VALIDATION_FAILED;
    }
}
