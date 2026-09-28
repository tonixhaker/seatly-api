<?php

declare(strict_types=1);

namespace App\Domain\Order\DTO;

final readonly class PlacedOrder
{
    /**
     * @param  list<string>  $ticket_ids
     */
    public function __construct(
        public OrderData $order,
        public array $ticket_ids,
        public bool $replayed,
    ) {}
}
