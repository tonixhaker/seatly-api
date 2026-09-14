<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read int $event_seat_id
 * @property-read int $price_cents
 */
final class OrderItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'event_seat_id' => $this->event_seat_id,
            'price_cents' => $this->price_cents,
        ];
    }
}
