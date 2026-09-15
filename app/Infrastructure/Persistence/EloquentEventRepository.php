<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Event\DTO\EventFilter;
use App\Domain\Event\Enums\EventStatus;
use App\Domain\Event\Models\Event;
use App\Domain\Event\Models\EventSeat;
use App\Domain\Event\Repositories\EventRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

final class EloquentEventRepository implements EventRepositoryInterface
{
    public function paginatePublished(EventFilter $filter): LengthAwarePaginator
    {
        $query = Event::query()
            ->with('venue')
            ->where('status', EventStatus::Published)
            ->orderBy('starts_at')
            ->orderBy('id');

        if ($filter->starts_from !== null) {
            $query->whereDate('starts_at', '>=', $filter->starts_from);
        }

        if ($filter->starts_until !== null) {
            $query->whereDate('starts_at', '<=', $filter->starts_until);
        }

        return $query->paginate(perPage: $filter->per_page, page: $filter->page);
    }

    public function findPublished(int $id): ?Event
    {
        return Event::query()
            ->with('venue')
            ->where('status', EventStatus::Published)
            ->find($id);
    }

    public function findOwnedByOrganizer(int $id, int $organizerId): ?Event
    {
        return Event::query()
            ->with('venue')
            ->where('organizer_id', $organizerId)
            ->find($id);
    }

    public function seatsForPublished(int $eventId): ?Collection
    {
        $exists = Event::query()
            ->where('status', EventStatus::Published)
            ->whereKey($eventId)
            ->exists();

        if (! $exists) {
            return null;
        }

        return EventSeat::query()
            ->where('event_id', $eventId)
            ->orderBy('section')
            ->orderBy('row')
            ->orderBy('number')
            ->get();
    }

    public function persist(Event $event): void
    {
        $event->save();
    }
}
