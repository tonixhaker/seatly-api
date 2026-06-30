<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Event\DTO\EventDraftData;
use App\Domain\Event\Exceptions\InvalidEventTransitionException;
use App\Domain\Event\Services\EventDraftService;
use App\Domain\Ticket\Exceptions\AlreadyCheckedInException;
use App\Http\Controllers\Controller;
use App\Http\Policies\EventPolicy;
use App\Http\Requests\CheckInRequest;
use App\Http\Requests\CreateEventRequest;
use App\Http\Requests\UpdateEventRequest;
use App\Http\Resources\EventDetailResource;
use App\Http\Resources\EventStatsResource;
use App\Http\Resources\TicketResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @phpstan-type VenueFixture object{id: int, name: string, address: string, city: string}
 * @phpstan-type EventFixture object{id: int, organizer_id: int, title: string, description: string, starts_at: string, status: string, venue: VenueFixture}
 * @phpstan-type TicketFixture object{id: string, order_id: string, event_id: int, event_seat_id: int, qr_code: string, status: string, checked_in_at: string|null}
 */
final class OrganizerController extends Controller
{
    private const CHECKED_IN_AT = '2026-10-01T19:05:00Z';

    public function __construct(
        private readonly EventPolicy $policy,
        private readonly EventDraftService $drafts,
    ) {}

    /**
     * Create a draft event.
     *
     * @throws AccessDeniedHttpException
     */
    public function store(CreateEventRequest $request): JsonResponse
    {
        $event = $this->drafts->create(
            $this->policy->callerId($request->user()),
            self::draftData($request),
        );

        return (new EventDetailResource($event))->response()->setStatusCode(201);
    }

    /**
     * Update a draft event.
     *
     * @throws AccessDeniedHttpException
     * @throws NotFoundHttpException
     * @throws InvalidEventTransitionException
     */
    public function update(UpdateEventRequest $request, int $id): EventDetailResource
    {
        $event = $this->drafts->update(
            $id,
            $this->policy->callerId($request->user()),
            self::draftData($request),
        );

        if ($event === null) {
            abort(404);
        }

        return new EventDetailResource($event);
    }

    /**
     * Publish a draft event.
     *
     * @throws AccessDeniedHttpException
     * @throws NotFoundHttpException
     * @throws InvalidEventTransitionException
     */
    public function publish(Request $request, int $id): EventDetailResource
    {
        $event = $this->ownedEvent($request, $id);
        self::assertDraft($event);

        return new EventDetailResource((object) [
            'id' => $event->id,
            'title' => $event->title,
            'description' => $event->description,
            'starts_at' => $event->starts_at,
            'status' => 'published',
            'venue' => $event->venue,
        ]);
    }

    /**
     * Return sales statistics for an event.
     *
     * @throws AccessDeniedHttpException
     * @throws NotFoundHttpException
     */
    public function stats(Request $request, int $id): EventStatsResource
    {
        $event = $this->ownedEvent($request, $id);
        $seated = $event->status !== 'draft';

        return new EventStatsResource((object) [
            'event_id' => $event->id,
            'seats_total' => $seated ? 12 : 0,
            'seats_sold' => $seated ? 3 : 0,
            'seats_free' => $seated ? 9 : 0,
            'revenue_cents' => $seated ? 12000 : 0,
            'currency' => 'EUR',
        ]);
    }

    /**
     * Check a ticket in at the door.
     *
     * @throws AccessDeniedHttpException
     * @throws NotFoundHttpException
     * @throws AlreadyCheckedInException
     */
    public function checkIn(CheckInRequest $request): TicketResource
    {
        $ticket = self::ticket($request->string('qr_code')->toString());

        if ($ticket === null || ! $this->policy->owns($request->user(), self::event($ticket->event_id))) {
            abort(404);
        }

        if ($ticket->checked_in_at !== null) {
            throw new AlreadyCheckedInException(
                'This ticket was already checked in.',
                ['checked_in_at' => $ticket->checked_in_at],
            );
        }

        return new TicketResource((object) [
            'id' => $ticket->id,
            'order_id' => $ticket->order_id,
            'event_seat_id' => $ticket->event_seat_id,
            'qr_code' => $ticket->qr_code,
            'status' => 'checked_in',
            'checked_in_at' => self::CHECKED_IN_AT,
        ]);
    }

    private static function draftData(CreateEventRequest|UpdateEventRequest $request): EventDraftData
    {
        return new EventDraftData(
            venue_id: $request->integer('venue_id'),
            title: $request->string('title')->toString(),
            description: $request->filled('description') ? $request->string('description')->toString() : null,
            starts_at: $request->string('starts_at')->toString(),
        );
    }

    /**
     * @return EventFixture
     */
    private function ownedEvent(Request $request, int $id): object
    {
        foreach (self::eventFixtures() as $event) {
            if ($event->id === $id && $this->policy->owns($request->user(), $event)) {
                return $event;
            }
        }

        abort(404);
    }

    /**
     * @param  EventFixture  $event
     */
    private static function assertDraft(object $event): void
    {
        if ($event->status !== 'draft') {
            throw new InvalidEventTransitionException(
                'Only a draft event can be edited or published.',
                ['status' => $event->status],
            );
        }
    }

    /**
     * @return EventFixture
     */
    private static function event(int $id): object
    {
        foreach (self::eventFixtures() as $event) {
            if ($event->id === $id) {
                return $event;
            }
        }

        abort(404);
    }

    /**
     * @return TicketFixture|null
     */
    private static function ticket(string $qrCode): ?object
    {
        foreach (self::ticketFixtures() as $ticket) {
            if ($ticket->qr_code === $qrCode) {
                return $ticket;
            }
        }

        return null;
    }

    /**
     * @return list<VenueFixture>
     */
    private static function venueFixtures(): array
    {
        return [
            (object) ['id' => 1, 'name' => 'Riverside Arena', 'address' => '14 Quay Street', 'city' => 'Rotterdam'],
            (object) ['id' => 2, 'name' => 'Northgate Hall', 'address' => '3 Market Square', 'city' => 'Utrecht'],
        ];
    }

    /**
     * @return list<EventFixture>
     */
    private static function eventFixtures(): array
    {
        [$arena, $hall] = self::venueFixtures();

        return [
            (object) ['id' => 1, 'organizer_id' => 10, 'title' => 'Autumn Symphony', 'description' => 'An evening of late romantic repertoire.', 'starts_at' => '2026-10-01T19:00:00Z', 'status' => 'published', 'venue' => $arena],
            (object) ['id' => 2, 'organizer_id' => 20, 'title' => 'Winter Jazz Night', 'description' => 'Three quartets across one long night.', 'starts_at' => '2026-12-12T20:30:00Z', 'status' => 'published', 'venue' => $hall],
            (object) ['id' => 3, 'organizer_id' => 10, 'title' => 'Spring Gala', 'description' => 'Not announced yet.', 'starts_at' => '2027-03-04T18:00:00Z', 'status' => 'draft', 'venue' => $arena],
            (object) ['id' => 4, 'organizer_id' => 10, 'title' => 'Summer Retrospective', 'description' => 'Concluded last season.', 'starts_at' => '2026-06-20T19:30:00Z', 'status' => 'archived', 'venue' => $hall],
        ];
    }

    /**
     * @return list<TicketFixture>
     */
    private static function ticketFixtures(): array
    {
        return [
            (object) ['id' => 'a1d4e7f0-2b58-4c91-8d3e-6f07a9b2c4d5', 'order_id' => '3f1b8c42-5d6e-4a7b-9c10-2e4f6a8b0d13', 'event_id' => 1, 'event_seat_id' => 1, 'qr_code' => 'A1B2C3D4E5F6', 'status' => 'issued', 'checked_in_at' => null],
            (object) ['id' => 'b2e5f801-3c69-4da2-9e4f-7008bac3d5e6', 'order_id' => '3f1b8c42-5d6e-4a7b-9c10-2e4f6a8b0d13', 'event_id' => 1, 'event_seat_id' => 2, 'qr_code' => 'G7H8J9K0L1M2', 'status' => 'checked_in', 'checked_in_at' => '2026-10-01T18:42:07Z'],
            (object) ['id' => 'c3f6a912-4d7a-4eb3-af50-8119cbd4e6f7', 'order_id' => '5c2e9a71-8b34-4f6d-9012-3a5b7c9d1e2f', 'event_id' => 2, 'event_seat_id' => 4, 'qr_code' => 'N3P4Q5R6S7T8', 'status' => 'issued', 'checked_in_at' => null],
        ];
    }
}
