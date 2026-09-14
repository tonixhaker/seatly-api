<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Order\Enums\OrderStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read string $id
 * @property-read int $event_id
 * @property-read OrderStatus $status
 * @property-read int $total_cents
 * @property-read string $currency
 * @property-read list<object{event_seat_id: int, price_cents: int}> $items
 */
final class OrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /** @format uuid */
            'id' => $this->id,
            'event_id' => $this->event_id,
            'status' => $this->status,
            'total_cents' => $this->total_cents,
            'currency' => $this->currency,
            'items' => OrderItemResource::collection($this->items),
        ];
    }
}
