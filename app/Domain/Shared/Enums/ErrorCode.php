<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

enum ErrorCode: string
{
    case VALIDATION_FAILED = 'VALIDATION_FAILED';
    case UNAUTHENTICATED = 'UNAUTHENTICATED';
    case FORBIDDEN = 'FORBIDDEN';
    case NOT_FOUND = 'NOT_FOUND';
    case SEATS_NOT_HELD = 'SEATS_NOT_HELD';
    case ALREADY_CHECKED_IN = 'ALREADY_CHECKED_IN';
    case PAYMENT_DECLINED = 'PAYMENT_DECLINED';
    case INTERNAL_ERROR = 'INTERNAL_ERROR';

    public function status(): int
    {
        return match ($this) {
            self::VALIDATION_FAILED, self::SEATS_NOT_HELD => 422,
            self::UNAUTHENTICATED => 401,
            self::FORBIDDEN => 403,
            self::NOT_FOUND => 404,
            self::ALREADY_CHECKED_IN => 409,
            self::PAYMENT_DECLINED => 402,
            self::INTERNAL_ERROR => 500,
        };
    }
}
