<?php

declare(strict_types=1);

namespace App\Domain\Venue\DTO;

use App\Domain\Venue\Models\Venue;

final readonly class VenueData
{
    public function __construct(
        public int $id,
        public string $name,
        public string $address,
        public string $city,
    ) {}

    public static function fromModel(Venue $venue): self
    {
        return new self(
            id: $venue->id,
            name: $venue->name,
            address: $venue->address,
            city: $venue->city,
        );
    }
}
