<?php

declare(strict_types=1);

use App\Domain\Event\DTO\EventData;
use App\Domain\Event\DTO\EventDraftData;
use App\Domain\Event\DTO\EventFilter;
use App\Domain\Event\Enums\EventStatus;
use App\Domain\Event\Exceptions\InvalidEventTransitionException;
use App\Domain\Event\Models\Event;
use App\Domain\Event\Repositories\EventRepositoryInterface;
use App\Domain\Event\Services\EventDraftService;
use App\Domain\Venue\Models\Venue;
use App\Domain\Venue\Repositories\VenueRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

$makeVenue = function (int $id, string $name): Venue {
    $venue = new Venue;
    $venue->setRawAttributes(['id' => $id, 'name' => $name, 'address' => '14 Quay Street', 'city' => 'Rotterdam']);

    return $venue;
};

$makeEvent = function (string $status, Venue $venue): Event {
    $event = new Event;
    $event->setRawAttributes([
        'id' => 42,
        'organizer_id' => 10,
        'venue_id' => $venue->getAttribute('id'),
        'title' => 'Spring Gala',
        'description' => 'Not announced yet.',
        'starts_at' => Carbon::parse('2027-03-04T18:00:00+00:00'),
        'status' => $status,
    ]);
    $event->setRelation('venue', $venue);

    return $event;
};

$fakeEvents = function (?Event $owned = null): EventRepositoryInterface {
    return new class($owned) implements EventRepositoryInterface
    {
        public int $persisted = 0;

        public ?Event $saw = null;

        /** @var array<string, mixed> */
        public array $storedAttributes = [];

        public ?int $askedId = null;

        public ?int $askedOrganizerId = null;

        public function __construct(private readonly ?Event $owned) {}

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
            $this->askedId = $id;
            $this->askedOrganizerId = $organizerId;

            return $this->owned;
        }

        public function seatsForPublished(int $eventId): ?Collection
        {
            return null;
        }

        public function publishWithSeats(Event $event, array $seats): ?array
        {
            return null;
        }

        public function persist(Event $event): void
        {
            $this->persisted++;
            $this->saw = $event;
            $this->storedAttributes = $event->getAttributes();
            $event->setAttribute('id', 42);
        }
    };
};

$fakeVenues = function (?Venue $venue): VenueRepositoryInterface {
    return new class($venue) implements VenueRepositoryInterface
    {
        public int $calls = 0;

        public function __construct(private readonly ?Venue $venue) {}

        public function findById(int $id): ?Venue
        {
            $this->calls++;

            return $this->venue;
        }
    };
};

$draft = new EventDraftData(
    venue_id: 7,
    title: 'Late Night Strings',
    description: 'A short programme of chamber works.',
    starts_at: '2027-05-01T19:00:00Z',
);

it('creates a draft owned by the organizer it was given', function () use ($fakeEvents, $fakeVenues, $makeVenue, $draft): void {
    $events = $fakeEvents();

    $data = (new EventDraftService($events, $fakeVenues($makeVenue(7, 'Riverside Arena'))))->create(10, $draft);

    expect($events->persisted)->toBe(1)
        ->and($events->saw?->getAttribute('organizer_id'))->toBe(10)
        ->and($events->saw?->getAttribute('status'))->toBe(EventStatus::Draft)
        ->and($events->saw?->getAttribute('title'))->toBe('Late Night Strings')
        ->and($data)->toBeInstanceOf(EventData::class)
        ->and($data->status)->toBe(EventStatus::Draft);
});

it('builds the created DTO venue from the venue repository, not from the model', function () use ($fakeEvents, $fakeVenues, $makeVenue, $draft): void {
    $venues = $fakeVenues($makeVenue(7, 'Riverside Arena'));

    $data = (new EventDraftService($fakeEvents(), $venues))->create(10, $draft);

    expect($venues->calls)->toBe(1)
        ->and($data->venue->id)->toBe(7)
        ->and($data->venue->name)->toBe('Riverside Arena');
});

it('refuses to persist when the validated venue has vanished', function () use ($fakeEvents, $fakeVenues, $draft): void {
    $events = $fakeEvents();

    expect(fn (): EventData => (new EventDraftService($events, $fakeVenues(null)))->create(10, $draft))
        ->toThrow(RuntimeException::class);

    expect($events->persisted)->toBe(0);
});

it('stores starts_at converted to UTC, so an offset is honoured and not dropped', function () use ($fakeEvents, $fakeVenues, $makeVenue): void {
    $events = $fakeEvents();

    (new EventDraftService($events, $fakeVenues($makeVenue(7, 'Riverside Arena'))))->create(10, new EventDraftData(
        venue_id: 7,
        title: 'Late Night Strings',
        description: null,
        starts_at: '2027-05-01T19:00:00+02:00',
    ));

    expect($events->storedAttributes['starts_at'])->toBe('2027-05-01 17:00:00');
});

it('returns null and touches nothing when the organizer owns no such event', function () use ($fakeEvents, $fakeVenues, $draft): void {
    $events = $fakeEvents();
    $venues = $fakeVenues(null);

    $result = (new EventDraftService($events, $venues))->update(3, 10, $draft);

    expect($result)->toBeNull()
        ->and($events->persisted)->toBe(0)
        ->and($venues->calls)->toBe(0)
        ->and($events->askedId)->toBe(3)
        ->and($events->askedOrganizerId)->toBe(10);
});

it('refuses to edit an event that is not a draft, naming the status it found', function (string $status) use ($fakeEvents, $fakeVenues, $makeVenue, $makeEvent, $draft): void {
    $events = $fakeEvents($makeEvent($status, $makeVenue(1, 'Riverside Arena')));

    try {
        (new EventDraftService($events, $fakeVenues($makeVenue(7, 'Northgate Hall'))))->update(42, 10, $draft);
        expect(false)->toBeTrue();
    } catch (InvalidEventTransitionException $e) {
        expect($e->details)->toBe(['status' => $status]);
    }

    expect($events->persisted)->toBe(0);
})->with(['published', 'archived']);

it('applies the update and rebuilds the venue from the repository, not from the stale relation', function () use ($fakeEvents, $fakeVenues, $makeVenue, $makeEvent, $draft): void {
    $stale = $makeVenue(1, 'Riverside Arena');
    $events = $fakeEvents($makeEvent('draft', $stale));
    $venues = $fakeVenues($makeVenue(7, 'Northgate Hall'));

    $data = (new EventDraftService($events, $venues))->update(42, 10, $draft);

    expect($events->persisted)->toBe(1)
        ->and($data?->title)->toBe('Late Night Strings')
        ->and($data?->status)->toBe(EventStatus::Draft)
        ->and($data?->venue->id)->toBe(7)
        ->and($data?->venue->name)->toBe('Northgate Hall');
});
