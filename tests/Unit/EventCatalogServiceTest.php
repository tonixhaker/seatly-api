<?php

declare(strict_types=1);

use App\Domain\Event\DTO\EventData;
use App\Domain\Event\DTO\EventFilter;
use App\Domain\Event\DTO\SeatData;
use App\Domain\Event\Enums\EventStatus;
use App\Domain\Event\Enums\SeatStatus;
use App\Domain\Event\Models\Event;
use App\Domain\Event\Models\EventSeat;
use App\Domain\Event\Repositories\EventRepositoryInterface;
use App\Domain\Event\Services\EventCatalogService;
use App\Domain\Venue\Models\Venue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

$makeEvent = function (int $id, string $title): Event {
    $venue = new Venue;
    $venue->setRawAttributes(['id' => 7, 'name' => 'Riverside Arena', 'address' => '14 Quay Street', 'city' => 'Rotterdam']);

    $event = new Event;
    $event->setRawAttributes([
        'id' => $id,
        'title' => $title,
        'description' => 'An evening of late romantic repertoire.',
        'starts_at' => Carbon::parse('2026-10-01T19:00:00+00:00'),
        'status' => EventStatus::Published->value,
    ]);
    $event->setRelation('venue', $venue);

    return $event;
};

$makeSeat = function (int $id, string $section, int $row, int $number, string $status = 'free'): EventSeat {
    $seat = new EventSeat;
    $seat->setRawAttributes([
        'id' => $id,
        'section' => $section,
        'row' => $row,
        'number' => $number,
        'x' => $number * 40,
        'y' => $row * 40,
        'price_cents' => 5000,
        'currency' => 'EUR',
        'status' => $status,
    ]);

    return $seat;
};

$fakeRepository = function (?LengthAwarePaginator $page = null, ?Event $found = null, ?Collection $seats = null): EventRepositoryInterface {
    return new class($page, $found, $seats) implements EventRepositoryInterface
    {
        public ?EventFilter $seen = null;

        public function __construct(private readonly ?LengthAwarePaginator $page, private readonly ?Event $found, private readonly ?Collection $seats) {}

        public function paginatePublished(EventFilter $filter): LengthAwarePaginator
        {
            $this->seen = $filter;

            return $this->page ?? new LengthAwarePaginator([], 0, 15, 1);
        }

        public function findPublished(int $id): ?Event
        {
            return $this->found;
        }

        public function findOwnedByOrganizer(int $id, int $organizerId): ?Event
        {
            return null;
        }

        public function seatsForPublished(int $eventId): ?Collection
        {
            return $this->seats;
        }

        public function persist(Event $event): void {}
    };
};

it('maps every model in the page to a DTO and keeps the pagination numbers', function () use ($makeEvent, $fakeRepository): void {
    $page = new LengthAwarePaginator([$makeEvent(1, 'Autumn Symphony'), $makeEvent(2, 'Winter Jazz Night')], 9, 2, 3);

    $result = (new EventCatalogService($fakeRepository($page)))->listPublished(new EventFilter);

    expect($result->items())->each->toBeInstanceOf(EventData::class)
        ->and($result->total())->toBe(9)
        ->and($result->perPage())->toBe(2)
        ->and($result->currentPage())->toBe(3);

    $first = $result->items()[0];

    expect($first)->toBeInstanceOf(EventData::class)
        ->and($first->title)->toBe('Autumn Symphony')
        ->and($first->starts_at)->toBe('2026-10-01T19:00:00Z')
        ->and($first->status)->toBe(EventStatus::Published)
        ->and($first->venue->city)->toBe('Rotterdam');
});

it('hands the repository the exact filter it was given, adding no hidden criteria', function () use ($fakeRepository): void {
    $repository = $fakeRepository();
    $filter = new EventFilter(starts_from: '2026-10-01', starts_until: '2026-12-31', page: 2, per_page: 25);

    (new EventCatalogService($repository))->listPublished($filter);

    expect($repository->seen)->toBe($filter);
});

it('returns null when the repository finds no published event, so the caller can 404', function () use ($fakeRepository): void {
    expect((new EventCatalogService($fakeRepository()))->findPublished(1))->toBeNull();
});

it('maps a found event, its venue included, to a DTO', function () use ($makeEvent, $fakeRepository): void {
    $data = (new EventCatalogService($fakeRepository(null, $makeEvent(42, 'Autumn Symphony'))))->findPublished(42);

    expect($data)->toBeInstanceOf(EventData::class)
        ->and($data?->id)->toBe(42)
        ->and($data?->venue->name)->toBe('Riverside Arena');
});

it('returns null for seats when the repository reports no published event, so the caller can 404', function () use ($fakeRepository): void {
    expect((new EventCatalogService($fakeRepository()))->seatsForPublished(1))->toBeNull();
});

it('maps every seat to a SeatData carrying all nine fields and a SeatStatus', function () use ($fakeRepository, $makeSeat): void {
    $seats = new Collection([$makeSeat(4, 'A', 1, 1), $makeSeat(9, 'B', 2, 3, 'sold')]);

    $result = (new EventCatalogService($fakeRepository(null, null, $seats)))->seatsForPublished(1);

    expect($result)->toBeArray()->toHaveCount(2)
        ->and($result[0])->toBeInstanceOf(SeatData::class)
        ->and($result[0]->id)->toBe(4)
        ->and($result[0]->section)->toBe('A')
        ->and($result[0]->row)->toBe(1)
        ->and($result[0]->number)->toBe(1)
        ->and($result[0]->x)->toBe(40)
        ->and($result[0]->y)->toBe(40)
        ->and($result[0]->price_cents)->toBe(5000)
        ->and($result[0]->currency)->toBe('EUR')
        ->and($result[0]->status)->toBe(SeatStatus::Free)
        ->and($result[1]->status)->toBe(SeatStatus::Sold);
});

it('preserves the repository order and adds no sort of its own', function () use ($fakeRepository, $makeSeat): void {
    $seats = new Collection([$makeSeat(3, 'B', 2, 3), $makeSeat(1, 'A', 1, 1), $makeSeat(2, 'A', 2, 2)]);

    $result = (new EventCatalogService($fakeRepository(null, null, $seats)))->seatsForPublished(1);

    expect(array_map(fn (SeatData $seat): int => $seat->id, $result ?? []))->toBe([3, 1, 2]);
});

it('returns an empty list, not null, for a published event with no seats', function () use ($fakeRepository): void {
    expect((new EventCatalogService($fakeRepository(null, null, new Collection)))->seatsForPublished(1))->toBe([]);
});
