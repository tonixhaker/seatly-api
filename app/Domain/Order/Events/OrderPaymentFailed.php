<?php

declare(strict_types=1);

namespace App\Domain\Order\Events;

use App\Domain\Shared\Contracts\DomainEvent;

final readonly class OrderPaymentFailed implements DomainEvent
{
    public const TYPE = 'order.payment_failed';

    /**
     * @param  list<int>  $seat_ids
     */
    public function __construct(
        public string $order_id,
        public int $event_id,
        public array $seat_ids,
        public string $session_id,
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
            'session_id' => $this->session_id,
        ];
    }
}
