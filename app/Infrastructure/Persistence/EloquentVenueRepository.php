<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Venue\Models\Venue;
use App\Domain\Venue\Repositories\VenueRepositoryInterface;

final class EloquentVenueRepository implements VenueRepositoryInterface
{
    public function findById(int $id): ?Venue
    {
        return Venue::query()->find($id);
    }
}
