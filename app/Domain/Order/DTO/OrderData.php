<?php

declare(strict_types=1);

namespace App\Domain\Order\DTO;

use App\Domain\Order\Enums\OrderStatus;

final readonly class OrderData
{
    /**
     * @param  list<OrderItemData>  $items
     */
    public function __construct(
        public string $id,
        public int $event_id,
        public OrderStatus $status,
        public int $total_cents,
        public string $currency,
        public array $items,
    ) {}

    /**
     * @return list<int>
     */
    public function seatIds(): array
    {
        return array_map(static fn (OrderItemData $item): int => $item->event_seat_id, $this->items);
    }
}
