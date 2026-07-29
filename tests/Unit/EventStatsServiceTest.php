<?php

declare(strict_types=1);

use App\Domain\Event\DTO\EventFilter;
use App\Domain\Event\DTO\EventStatsData;
use App\Domain\Event\Models\Event;
use App\Domain\Event\Repositories\EventRepositoryInterface;
use App\Domain\Event\Services\EventStatsService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

$fakeEvents = function (?array $stats): EventRepositoryInterface {
    return new class($stats) implements EventRepositoryInterface
    {
        /** @var list<array{int, int}> */
        public array $asked = [];

        /**
         * @param  array{seats_total: int, seats_sold: int, revenue_cents: int, currency: string|null}|null  $stats
         */
        public function __construct(private readonly ?array $stats) {}

        public function paginatePublished(EventFilter $filter): LengthAwarePaginator
        {
            return new LengthAwarePaginator([], 0, 15, 1);
        }

        public function findPublished(int $id): ?Event
        {
            return null;
        }

        public function findOwnedByOrganizer(int $id, int $organizerId): ?Event
        {
            return null;
        }

        public function seatsForPublished(int $eventId): ?Collection
        {
            return null;
        }

        public function publishWithSeats(Event $event, array $seats): ?array
        {
            return null;
        }

        public function statsForOrganizer(int $id, int $organizerId): ?array
        {
            $this->asked[] = [$id, $organizerId];

            return $this->stats;
        }

        public function persist(Event $event): void {}
    };
};

it('returns null when the event is not the organizer\'s and asks the repository with both ids', function () use ($fakeEvents): void {
    $events = $fakeEvents(null);

    $result = (new EventStatsService($events))->forOrganizer(42, 10);

    expect($result)->toBeNull()
        ->and($events->asked)->toBe([[42, 10]]);
});

it('derives seats_free from total minus sold and carries the stored currency', function () use ($fakeEvents): void {
    $events = $fakeEvents(['seats_total' => 12, 'seats_sold' => 3, 'revenue_cents' => 12000, 'currency' => 'USD']);

    $result = (new EventStatsService($events))->forOrganizer(42, 10);

    expect($result)->toBeInstanceOf(EventStatsData::class)
        ->and((array) $result)->toBe([
            'event_id' => 42,
            'seats_total' => 12,
            'seats_sold' => 3,
            'seats_free' => 9,
            'revenue_cents' => 12000,
            'currency' => 'USD',
        ])
        ->and($events->asked)->toBe([[42, 10]]);
});

it('reports zeros and falls back to EUR when the event has no seats', function () use ($fakeEvents): void {
    $events = $fakeEvents(['seats_total' => 0, 'seats_sold' => 0, 'revenue_cents' => 0, 'currency' => null]);

    $result = (new EventStatsService($events))->forOrganizer(7, 10);

    expect((array) $result)->toBe([
        'event_id' => 7,
        'seats_total' => 0,
        'seats_sold' => 0,
        'seats_free' => 0,
        'revenue_cents' => 0,
        'currency' => 'EUR',
    ]);
});
