<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read int $event_id
 * @property-read int $seats_total
 * @property-read int $seats_sold
 * @property-read int $seats_free
 * @property-read int $revenue_cents
 * @property-read string $currency
 */
final class EventStatsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'event_id' => $this->event_id,
            'seats_total' => $this->seats_total,
            'seats_sold' => $this->seats_sold,
            'seats_free' => $this->seats_free,
            'revenue_cents' => $this->revenue_cents,
            'currency' => $this->currency,
        ];
    }
}
