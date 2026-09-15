<?php

declare(strict_types=1);

namespace App\Domain\User\DTO;

use App\Domain\User\Enums\UserRole;
use App\Domain\User\Models\User;
use RuntimeException;

final readonly class UserData
{
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
        public UserRole $role,
    ) {}

    public static function fromModel(User $user): self
    {
        return new self(
            id: $user->id,
            name: $user->name,
            email: $user->email,
            role: $user->role ?? throw new RuntimeException('Authenticated user has no role.'),
        );
    }
}
