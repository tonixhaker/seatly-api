<?php

declare(strict_types=1);

namespace App\Domain\Event\Services;

use App\Domain\Event\DTO\EventData;
use App\Domain\Event\DTO\EventDraftData;
use App\Domain\Event\Enums\EventStatus;
use App\Domain\Event\Exceptions\InvalidEventTransitionException;
use App\Domain\Event\Models\Event;
use App\Domain\Event\Repositories\EventRepositoryInterface;
use App\Domain\Venue\Models\Venue;
use App\Domain\Venue\Repositories\VenueRepositoryInterface;
use Illuminate\Support\Carbon;
use RuntimeException;

final readonly class EventDraftService
{
    public function __construct(
        private EventRepositoryInterface $events,
        private VenueRepositoryInterface $venues,
    ) {}

    public function create(int $organizerId, EventDraftData $draft): EventData
    {
        $venue = $this->venue($draft->venue_id);

        $event = new Event([
            'organizer_id' => $organizerId,
            'venue_id' => $draft->venue_id,
            'title' => $draft->title,
            'description' => $draft->description,
            'starts_at' => Carbon::parse($draft->starts_at)->utc(),
            'status' => EventStatus::Draft,
        ]);

        $this->events->persist($event);
        $event->setRelation('venue', $venue);

        return EventData::fromModel($event);
    }

    public function update(int $id, int $organizerId, EventDraftData $draft): ?EventData
    {
        $event = $this->events->findOwnedByOrganizer($id, $organizerId);

        if ($event === null) {
            return null;
        }

        if ($event->status !== EventStatus::Draft) {
            throw new InvalidEventTransitionException(
                'Only a draft event can be edited or published.',
                ['status' => $event->status->value],
            );
        }

        $venue = $this->venue($draft->venue_id);

        $event->fill([
            'venue_id' => $draft->venue_id,
            'title' => $draft->title,
            'description' => $draft->description,
            'starts_at' => Carbon::parse($draft->starts_at)->utc(),
        ]);

        $this->events->persist($event);
        $event->setRelation('venue', $venue);

        return EventData::fromModel($event);
    }

    private function venue(int $id): Venue
    {
        return $this->venues->findById($id) ?? throw new RuntimeException('The validated venue no longer exists.');
    }
}
