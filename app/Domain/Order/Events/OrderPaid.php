<?php

declare(strict_types=1);

namespace App\Domain\Order\Events;

use App\Domain\Shared\Contracts\DomainEvent;

final readonly class OrderPaid implements DomainEvent
{
    public const TYPE = 'order.paid';

    /**
     * @param  list<int>  $seat_ids
     */
    public function __construct(
        public string $order_id,
        public int $event_id,
        public array $seat_ids,
        public int $buyer_id,
    ) {}

    public function type(): string
    {
        return self::TYPE;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'order_id' => $this->order_id,
            'event_id' => $this->event_id,
            'seat_ids' => $this->seat_ids,
            'buyer_id' => $this->buyer_id,
        ];
    }
}
