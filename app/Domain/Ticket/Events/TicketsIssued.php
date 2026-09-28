<?php

declare(strict_types=1);

namespace App\Domain\Ticket\Events;

use App\Domain\Shared\Contracts\DomainEvent;

final readonly class TicketsIssued implements DomainEvent
{
    public const TYPE = 'tickets.issued';

    /**
     * @param  list<string>  $ticket_ids
     */
    public function __construct(
        public string $order_id,
        public array $ticket_ids,
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
            'ticket_ids' => $this->ticket_ids,
        ];
    }
}
