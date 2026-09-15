<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\User\Models\User;
use App\Domain\User\Repositories\UserRepositoryInterface;

final class EloquentUserRepository implements UserRepositoryInterface
{
    public function findByEmail(string $email): ?User
    {
        return User::query()->where('email', $email)->first();
    }

    public function persist(User $user): void
    {
        $user->save();
    }
}
