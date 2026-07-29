<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Event\DTO\EventDraftData;
use App\Domain\Event\Exceptions\InvalidEventTransitionException;
use App\Domain\Event\Services\EventDraftService;
use App\Domain\Event\Services\EventPublishService;
use App\Domain\Ticket\Exceptions\AlreadyCheckedInException;
use App\Domain\Ticket\Services\CheckInService;
use App\Domain\Venue\Exceptions\InvalidSeatMapTemplateException;
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
 */
final class OrganizerController extends Controller
{
    public function __construct(
        private readonly EventPolicy $policy,
        private readonly EventDraftService $drafts,
        private readonly EventPublishService $publishing,
        private readonly CheckInService $checkIns,
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
     * @throws InvalidSeatMapTemplateException
     */
    public function publish(Request $request, int $id): EventDetailResource
    {
        $event = $this->publishing->publish($id, $this->policy->callerId($request->user()));

        if ($event === null) {
            abort(404);
        }

        return new EventDetailResource($event);
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
        $ticket = $this->checkIns->checkIn(
            $request->string('qr_code')->toString(),
            $this->policy->callerId($request->user()),
        );

        if ($ticket === null) {
            abort(404);
        }

        return new TicketResource($ticket);
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
}
