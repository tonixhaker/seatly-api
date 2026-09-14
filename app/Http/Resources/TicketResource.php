<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read string $id
 * @property-read string $order_id
 * @property-read int $event_seat_id
 * @property-read string $qr_code
 * @property-read string $status
 * @property-read string|null $checked_in_at
 */
final class TicketResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /** @format uuid */
            'id' => $this->id,
            /** @format uuid */
            'order_id' => $this->order_id,
            'event_seat_id' => $this->event_seat_id,
            'qr_code' => $this->qr_code,
            'status' => $this->status,
            /** @format date-time */
            'checked_in_at' => $this->checked_in_at,
        ];
    }
}
