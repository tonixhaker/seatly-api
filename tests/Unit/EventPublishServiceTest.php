<?php

declare(strict_types=1);

use App\Domain\Event\Contracts\EventPublisherInterface;
use App\Domain\Event\DTO\EventFilter;
use App\Domain\Event\DTO\SeatBlueprint;
use App\Domain\Event\Enums\EventStatus;
use App\Domain\Event\Events\EventPublished;
use App\Domain\Event\Exceptions\InvalidEventTransitionException;
use App\Domain\Event\Models\Event;
use App\Domain\Event\Models\EventSeat;
use App\Domain\Event\Repositories\EventRepositoryInterface;
use App\Domain\Event\Services\EventPublishService;
use App\Domain\Venue\Exceptions\InvalidSeatMapTemplateException;
use App\Domain\Venue\Models\Venue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

$grid = function (string $name, int $rows, int $seats, int $priceCents, int $xOffset = 0): array {
    $built = [];

    for ($row = 1; $row <= $rows; $row++) {
        for ($number = 1; $number <= $seats; $number++) {
            $built[] = ['row' => $row, 'number' => $number, 'x' => $xOffset + $number * 40, 'y' => $row * 40, 'price_cents' => $priceCents];
        }
    }

    return ['name' => $name, 'seats' => $built];
};

$makeEvent = function (string $status, mixed $template): Event {
    $venue = new Venue;
    $venue->setRawAttributes(['id' => 7, 'name' => 'Riverside Arena', 'address' => '14 Quay Street', 'city' => 'Rotterdam', 'seat_map_template' => json_encode($template, JSON_THROW_ON_ERROR)]);

    $event = new Event;
    $event->setRawAttributes([
        'id' => 42,
        'organizer_id' => 10,
        'venue_id' => 7,
        'title' => 'Spring Gala',
        'description' => 'Not announced yet.',
        'starts_at' => Carbon::parse('2027-03-04T18:00:00+00:00'),
        'status' => $status,
    ]);
    $event->setRelation('venue', $venue);

    return $event;
};

$fakeEvents = function (?Event $owned, ?array $seatIds = [1, 2]): EventRepositoryInterface {
    return new class($owned, $seatIds) implements EventRepositoryInterface
    {
        public int $publishCalls = 0;

        /** @var list<SeatBlueprint> */
        public array $received = [];

        public ?int $askedId = null;

        public ?int $askedOrganizerId = null;

        /**
         * @param  list<int>|null  $seatIds
         */
        public function __construct(private readonly ?Event $owned, private readonly ?array $seatIds) {}

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
            return new Collection([new EventSeat]);
        }

        public function publishWithSeats(Event $event, array $seats): ?array
        {
            $this->publishCalls++;
            $this->received = $seats;

            return $this->seatIds;
        }

        public function persist(Event $event): void {}
    };
};

$fakePublisher = function (): EventPublisherInterface {
    return new class implements EventPublisherInterface
    {
        /** @var list<EventPublished> */
        public array $emitted = [];

        public function publish(EventPublished $event): void
        {
            $this->emitted[] = $event;
        }
    };
};

it('returns null for an event the organizer does not own, without publishing anything', function () use ($fakeEvents, $fakePublisher): void {
    $events = $fakeEvents(null);
    $publisher = $fakePublisher();

    expect((new EventPublishService($events, $publisher))->publish(42, 10))->toBeNull()
        ->and($events->askedId)->toBe(42)
        ->and($events->askedOrganizerId)->toBe(10)
        ->and($events->publishCalls)->toBe(0)
        ->and($publisher->emitted)->toBe([]);
});

it('refuses an event that is not a draft, naming its real status', function (string $status) use ($grid, $makeEvent, $fakeEvents, $fakePublisher): void {
    $events = $fakeEvents($makeEvent($status, ['sections' => [$grid('A', 1, 2, 5000)]]));
    $publisher = $fakePublisher();

    expect(fn (): mixed => (new EventPublishService($events, $publisher))->publish(42, 10))
        ->toThrow(function (InvalidEventTransitionException $e) use ($status): void {
            expect($e->details)->toBe(['status' => $status]);
        });

    expect($events->publishCalls)->toBe(0)
        ->and($publisher->emitted)->toBe([]);
})->with(['published', 'archived']);

it('builds one blueprint per template seat, in EUR, with the template values', function () use ($grid, $makeEvent, $fakeEvents, $fakePublisher): void {
    $events = $fakeEvents($makeEvent('draft', ['sections' => [$grid('A', 2, 3, 5000), $grid('B', 1, 2, 3500, 200)]]), [11, 12, 13, 14, 15, 16, 17, 18]);
    $publisher = $fakePublisher();

    $data = (new EventPublishService($events, $publisher))->publish(42, 10);

    expect($events->publishCalls)->toBe(1)
        ->and($events->received)->toHaveCount(8)
        ->and($events->received[0])->toEqual(new SeatBlueprint('A', 1, 1, 40, 40, 5000, 'EUR'))
        ->and($events->received[5])->toEqual(new SeatBlueprint('A', 2, 3, 120, 80, 5000, 'EUR'))
        ->and($events->received[6])->toEqual(new SeatBlueprint('B', 1, 1, 240, 40, 3500, 'EUR'))
        ->and($data?->status)->toBe(EventStatus::Published);
});

it('emits exactly one event.published carrying the generated seat ids', function () use ($grid, $makeEvent, $fakeEvents, $fakePublisher): void {
    $events = $fakeEvents($makeEvent('draft', ['sections' => [$grid('A', 1, 2, 5000)]]), [11, 12]);
    $publisher = $fakePublisher();

    (new EventPublishService($events, $publisher))->publish(42, 10);

    expect($publisher->emitted)->toHaveCount(1)
        ->and($publisher->emitted[0]->event_id)->toBe(42)
        ->and($publisher->emitted[0]->seat_ids)->toBe([11, 12])
        ->and(EventPublished::TYPE)->toBe('event.published');
});

it('refuses and emits nothing when the locked row was no longer a draft', function () use ($grid, $makeEvent, $fakeEvents, $fakePublisher): void {
    $events = $fakeEvents($makeEvent('draft', ['sections' => [$grid('A', 1, 2, 5000)]]), null);
    $publisher = $fakePublisher();

    expect(fn (): mixed => (new EventPublishService($events, $publisher))->publish(42, 10))
        ->toThrow(function (InvalidEventTransitionException $e): void {
            expect($e->details)->toBe(['status' => 'published']);
        });

    expect($events->publishCalls)->toBe(1)
        ->and($publisher->emitted)->toBe([]);
});

it('rejects a malformed template before it reaches the repository or the publisher', function (mixed $template) use ($makeEvent, $fakeEvents, $fakePublisher): void {
    $events = $fakeEvents($makeEvent('draft', $template));
    $publisher = $fakePublisher();

    expect(fn (): mixed => (new EventPublishService($events, $publisher))->publish(42, 10))
        ->toThrow(InvalidSeatMapTemplateException::class);

    expect($events->publishCalls)->toBe(0)
        ->and($publisher->emitted)->toBe([]);
})->with([
    'not an array' => ['a string'],
    'no sections' => [['sections' => []]],
    'sections missing' => [[]],
    'nameless section' => [['sections' => [['seats' => [['row' => 1, 'number' => 1, 'x' => 1, 'y' => 1, 'price_cents' => 1]]]]]],
    'duplicate section name' => [['sections' => [
        ['name' => 'A', 'seats' => [['row' => 1, 'number' => 1, 'x' => 1, 'y' => 1, 'price_cents' => 1]]],
        ['name' => 'A', 'seats' => [['row' => 2, 'number' => 1, 'x' => 1, 'y' => 1, 'price_cents' => 1]]],
    ]]],
    'empty section' => [['sections' => [['name' => 'A', 'seats' => []]]]],
    'missing x' => [['sections' => [['name' => 'A', 'seats' => [['row' => 1, 'number' => 1, 'y' => 1, 'price_cents' => 1]]]]]],
    'missing y' => [['sections' => [['name' => 'A', 'seats' => [['row' => 1, 'number' => 1, 'x' => 1, 'price_cents' => 1]]]]]],
    'missing price_cents' => [['sections' => [['name' => 'A', 'seats' => [['row' => 1, 'number' => 1, 'x' => 1, 'y' => 1]]]]]],
    'string coordinate' => [['sections' => [['name' => 'A', 'seats' => [['row' => 1, 'number' => 1, 'x' => '40', 'y' => 1, 'price_cents' => 1]]]]]],
    'float coordinate' => [['sections' => [['name' => 'A', 'seats' => [['row' => 1, 'number' => 1, 'x' => 40.5, 'y' => 1, 'price_cents' => 1]]]]]],
    'duplicate position in one section' => [['sections' => [['name' => 'A', 'seats' => [
        ['row' => 1, 'number' => 1, 'x' => 1, 'y' => 1, 'price_cents' => 1],
        ['row' => 1, 'number' => 1, 'x' => 2, 'y' => 2, 'price_cents' => 1],
    ]]]]],
]);

it('names the exact defect in the message a rejected template returns to the organizer', function (mixed $template, string $message) use ($makeEvent, $fakeEvents, $fakePublisher): void {
    $events = $fakeEvents($makeEvent('draft', $template));

    expect(fn (): mixed => (new EventPublishService($events, $fakePublisher()))->publish(42, 10))
        ->toThrow(InvalidSeatMapTemplateException::class, $message);
})->with([
    'the template is not an object' => [
        'a string',
        'The venue seat map template is not an object.',
    ],
    'the template declares no sections' => [
        ['sections' => []],
        'The venue seat map template declares no sections.',
    ],
    'a section is not an object' => [
        ['sections' => ['Balcony']],
        'A section of the venue seat map template is not an object.',
    ],
    'a section has no name' => [
        ['sections' => [['seats' => [['row' => 2, 'number' => 7, 'x' => 80, 'y' => 80, 'price_cents' => 5000]]]]],
        'A section of the venue seat map template has no name.',
    ],
    'a section name is repeated' => [
        ['sections' => [
            ['name' => 'Balcony', 'seats' => [['row' => 2, 'number' => 7, 'x' => 80, 'y' => 80, 'price_cents' => 5000]]],
            ['name' => 'Balcony', 'seats' => [['row' => 3, 'number' => 7, 'x' => 80, 'y' => 120, 'price_cents' => 5000]]],
        ]],
        'The venue seat map template names the same section twice.',
    ],
    'a section has no seats' => [
        ['sections' => [['name' => 'Balcony', 'seats' => []]]],
        'Section "Balcony" of the venue seat map template has no seats.',
    ],
    'a seat is not an object' => [
        ['sections' => [['name' => 'Balcony', 'seats' => ['front row']]]],
        'A seat in section "Balcony" is not an object.',
    ],
    'a seat has no x' => [
        ['sections' => [['name' => 'Balcony', 'seats' => [['row' => 2, 'number' => 7, 'y' => 80, 'price_cents' => 5000]]]]],
        'A seat in section "Balcony" has no integer "x".',
    ],
    'a seat has no price_cents' => [
        ['sections' => [['name' => 'Balcony', 'seats' => [['row' => 2, 'number' => 7, 'x' => 80, 'y' => 80]]]]],
        'A seat in section "Balcony" has no integer "price_cents".',
    ],
    'two seats share one position' => [
        ['sections' => [['name' => 'Balcony', 'seats' => [
            ['row' => 2, 'number' => 7, 'x' => 80, 'y' => 80, 'price_cents' => 5000],
            ['row' => 2, 'number' => 7, 'x' => 120, 'y' => 80, 'price_cents' => 5000],
        ]]]],
        'Section "Balcony" places two seats at row 2, number 7.',
    ],
]);
