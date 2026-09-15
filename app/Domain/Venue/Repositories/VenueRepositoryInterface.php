<?php

declare(strict_types=1);

namespace App\Domain\Venue\Repositories;

use App\Domain\Venue\Models\Venue;

interface VenueRepositoryInterface
{
    public function findById(int $id): ?Venue;
}
