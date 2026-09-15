<?php

declare(strict_types=1);

namespace App\Domain\User\Contracts;

use App\Domain\User\Enums\UserRole;

interface HasRole
{
    public function hasRole(UserRole $role): bool;
}
