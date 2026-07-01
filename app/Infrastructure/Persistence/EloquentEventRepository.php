<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Event\DTO\EventFilter;
use App\Domain\Event\DTO\SeatBlueprint;
use App\Domain\Event\Enums\EventStatus;
use App\Domain\Event\Enums\SeatStatus;
use App\Domain\Event\Models\Event;
use App\Domain\Event\Models\EventSeat;
use App\Domain\Event\Repositories\EventRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class EloquentEventRepository implements EventRepositoryInterface
{
    private const INSERT_BATCH = 500;

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

    public function publishWithSeats(Event $event, array $seats): ?array
    {
        return DB::transaction(function () use ($event, $seats): ?array {
            $locked = Event::query()->lockForUpdate()->find($event->id);

            if ($locked === null || $locked->status !== EventStatus::Draft) {
                return null;
            }

            foreach (array_chunk($seats, self::INSERT_BATCH) as $chunk) {
                DB::table('event_seats')->insert(array_map(
                    static fn (SeatBlueprint $seat): array => [
                        'event_id' => $locked->id,
                        'section' => $seat->section,
                        'row' => $seat->row,
                        'number' => $seat->number,
                        'x' => $seat->x,
                        'y' => $seat->y,
                        'price_cents' => $seat->price_cents,
                        'currency' => $seat->currency,
                        'status' => SeatStatus::Free->value,
                    ],
                    $chunk,
                ));
            }

            $locked->fill(['status' => EventStatus::Published])->save();

            $ids = EventSeat::query()
                ->where('event_id', $locked->id)
                ->orderBy('id')
                ->pluck('id')
                ->all();

            return array_values(array_map(
                static fn (mixed $id): int => is_numeric($id) ? (int) $id : throw new RuntimeException('A generated seat has no numeric identifier.'),
                $ids,
            ));
        });
    }

    public function persist(Event $event): void
    {
        $event->save();
    }
}
