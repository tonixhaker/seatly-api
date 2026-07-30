<?php

declare(strict_types=1);

namespace App\Domain\Venue\Repositories;

use App\Domain\Venue\Models\Venue;
use Illuminate\Database\Eloquent\Collection;

interface VenueRepositoryInterface
{
    public function findById(int $id): ?Venue;

    /**
     * @return Collection<int, Venue>
     */
    public function all(): Collection;
}
