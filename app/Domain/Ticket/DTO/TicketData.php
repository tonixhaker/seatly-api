<?php

declare(strict_types=1);

namespace App\Domain\Ticket\DTO;

final readonly class TicketData
{
    public function __construct(
        public string $id,
        public string $order_id,
        public int $event_seat_id,
        public string $qr_code,
        public string $status,
        public ?string $checked_in_at,
    ) {}
}
