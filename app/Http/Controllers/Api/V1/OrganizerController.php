<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Event\DTO\EventDraftData;
use App\Domain\Event\Exceptions\InvalidEventTransitionException;
use App\Domain\Event\Services\EventDraftService;
use App\Domain\Event\Services\EventPublishService;
use App\Domain\Event\Services\EventStatsService;
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

final class OrganizerController extends Controller
{
    public function __construct(
        private readonly EventPolicy $policy,
        private readonly EventDraftService $drafts,
        private readonly EventPublishService $publishing,
        private readonly CheckInService $checkIns,
        private readonly EventStatsService $eventStats,
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
        $stats = $this->eventStats->forOrganizer($id, $this->policy->callerId($request->user()));

        if ($stats === null) {
            abort(404);
        }

        return new EventStatsResource($stats);
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
}
