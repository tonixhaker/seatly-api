<?php

declare(strict_types=1);

namespace App\Http\Policies;

use Illuminate\Contracts\Auth\Authenticatable;

final class EventPolicy
{
    /**
     * @param  object{organizer_id: int}  $event
     */
    public function owns(?Authenticatable $user, object $event): bool
    {
        $id = $user?->getAuthIdentifier();

        return is_numeric($id) && (int) $id === $event->organizer_id;
    }
}
