<?php

declare(strict_types=1);

namespace App\Domain\User\DTO;

final readonly class AuthResult
{
    public function __construct(
        public string $token,
        public UserData $user,
    ) {}
}
