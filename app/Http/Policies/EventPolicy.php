<?php

declare(strict_types=1);

namespace App\Http\Policies;

use Illuminate\Contracts\Auth\Authenticatable;
use RuntimeException;

final class EventPolicy
{
    public function callerId(?Authenticatable $user): int
    {
        $id = $user?->getAuthIdentifier();

        return is_numeric($id) ? (int) $id : throw new RuntimeException('The authenticated user has no numeric identifier.');
    }
}
