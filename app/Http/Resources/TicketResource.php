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
 * @property-read int $event_id
 * @property-read string $event_title
 * @property-read string $event_starts_at
 * @property-read string $seat_section
 * @property-read int $seat_row
 * @property-read int $seat_number
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
            'event' => [
                'id' => $this->event_id,
                'title' => $this->event_title,
                /** @format date-time */
                'starts_at' => $this->event_starts_at,
            ],
            'seat' => [
                'section' => $this->seat_section,
                'row' => $this->seat_row,
                'number' => $this->seat_number,
            ],
        ];
    }
}
