<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Venue\Models\Venue;
use App\Domain\Venue\Repositories\VenueRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

final class EloquentVenueRepository implements VenueRepositoryInterface
{
    public function findById(int $id): ?Venue
    {
        return Venue::query()->find($id);
    }

    public function all(): Collection
    {
        return Venue::query()->orderBy('name')->orderBy('id')->get();
    }
}
