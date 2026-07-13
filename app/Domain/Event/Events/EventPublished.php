<?php

declare(strict_types=1);

namespace App\Domain\Event\Events;

use App\Domain\Shared\Contracts\DomainEvent;

final readonly class EventPublished implements DomainEvent
{
    public const TYPE = 'event.published';

    /**
     * @param  list<int>  $seat_ids
     */
    public function __construct(
        public int $event_id,
        public array $seat_ids,
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
            'event_id' => $this->event_id,
            'seat_ids' => $this->seat_ids,
        ];
    }
}
