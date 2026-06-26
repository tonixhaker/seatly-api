<?php

declare(strict_types=1);

namespace App\Domain\User\DTO;

final readonly class LoginCredentials
{
    public function __construct(
        public string $email,
        public string $password,
    ) {}
}
