<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read int $id
 * @property-read string $title
 * @property-read string $description
 * @property-read string $starts_at
 * @property-read string $status
 * @property-read object{id: int, name: string, address: string, city: string} $venue
 */
final class EventDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            /** @format date-time */
            'starts_at' => $this->starts_at,
            'status' => $this->status,
            'venue' => new VenueResource($this->venue),
        ];
    }
}
