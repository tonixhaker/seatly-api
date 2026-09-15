<?php

declare(strict_types=1);

use App\Domain\Event\DTO\EventData;
use App\Domain\Event\DTO\EventFilter;
use App\Domain\Event\Enums\EventStatus;
use App\Domain\Event\Models\Event;
use App\Domain\Event\Repositories\EventRepositoryInterface;
use App\Domain\Event\Services\EventCatalogService;
use App\Domain\Venue\Models\Venue;
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

$fakeRepository = function (?LengthAwarePaginator $page = null, ?Event $found = null): EventRepositoryInterface {
    return new class($page, $found) implements EventRepositoryInterface
    {
        public ?EventFilter $seen = null;

        public function __construct(private readonly ?LengthAwarePaginator $page, private readonly ?Event $found) {}

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
