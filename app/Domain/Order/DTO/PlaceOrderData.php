<?php

declare(strict_types=1);

namespace App\Domain\Order\DTO;

final readonly class PlaceOrderData
{
    /**
     * @param  list<int>  $seat_ids
     */
    public function __construct(
        public int $buyer_id,
        public int $event_id,
        public array $seat_ids,
        public string $session_id,
        public string $idempotency_key,
    ) {}
}
