<?php

declare(strict_types=1);

namespace App\Domain\Event\Events;

final readonly class EventPublished
{
    public const TYPE = 'event.published';

    /**
     * @param  list<int>  $seat_ids
     */
    public function __construct(
        public int $event_id,
        public array $seat_ids,
    ) {}
}
