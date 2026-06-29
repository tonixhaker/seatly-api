<?php

declare(strict_types=1);

namespace App\Domain\User\Repositories;

use App\Domain\User\Models\User;

interface UserRepositoryInterface
{
    public function findByEmail(string $email): ?User;

    public function persist(User $user): void;
}
