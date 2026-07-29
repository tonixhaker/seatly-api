<?php

declare(strict_types=1);

namespace App\Domain\Event\Services;

use App\Domain\Event\DTO\EventStatsData;
use App\Domain\Event\Repositories\EventRepositoryInterface;

final readonly class EventStatsService
{
    public function __construct(private EventRepositoryInterface $events) {}

    public function forOrganizer(int $id, int $organizerId): ?EventStatsData
    {
        $stats = $this->events->statsForOrganizer($id, $organizerId);

        if ($stats === null) {
            return null;
        }

        return new EventStatsData(
            event_id: $id,
            seats_total: $stats['seats_total'],
            seats_sold: $stats['seats_sold'],
            seats_free: $stats['seats_total'] - $stats['seats_sold'],
            revenue_cents: $stats['revenue_cents'],
            currency: $stats['currency'] ?? EventPublishService::CURRENCY,
        );
    }
}
