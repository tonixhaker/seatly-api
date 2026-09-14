<?php

declare(strict_types=1);

namespace App\Domain\Shared\Exceptions;

use App\Domain\Shared\Enums\ErrorCode;
use RuntimeException;

abstract class DomainException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(string $message, public readonly array $details = [])
    {
        parent::__construct($message);
    }

    abstract public function errorCode(): ErrorCode;
}
