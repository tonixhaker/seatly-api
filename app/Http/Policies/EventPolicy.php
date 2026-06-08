<?php

declare(strict_types=1);

namespace App\Http\Policies;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;

final class EventPolicy
{
    /**
     * @param  object{organizer_id: int}  $event
     */
    public function owns(?Authenticatable $user, object $event): bool
    {
        return $user instanceof User && $user->getAttribute('id') === $event->organizer_id;
    }
}
