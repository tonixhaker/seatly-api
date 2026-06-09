<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read int $id
 * @property-read string $title
 * @property-read string $starts_at
 * @property-read string $status
 * @property-read object{id: int, name: string} $venue
 */
final class EventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            /** @format date-time */
            'starts_at' => $this->starts_at,
            'status' => $this->status,
            'venue' => [
                /** @var int */
                'id' => $this->venue->id,
                'name' => $this->venue->name,
            ],
        ];
    }
}
