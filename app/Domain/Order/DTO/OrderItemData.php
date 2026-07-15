<?php

declare(strict_types=1);

namespace App\Domain\Order\DTO;

final readonly class OrderItemData
{
    public function __construct(
        public int $event_seat_id,
        public int $price_cents,
    ) {}
}
