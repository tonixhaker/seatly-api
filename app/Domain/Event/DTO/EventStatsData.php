<?php

declare(strict_types=1);

namespace App\Domain\Event\DTO;

final readonly class EventStatsData
{
    public function __construct(
        public int $event_id,
        public int $seats_total,
        public int $seats_sold,
        public int $seats_free,
        public int $revenue_cents,
        public string $currency,
    ) {}
}
