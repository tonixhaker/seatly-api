<?php

declare(strict_types=1);

namespace App\Domain\Event\Repositories;

use App\Domain\Event\DTO\EventFilter;
use App\Domain\Event\Models\Event;
use App\Domain\Event\Models\EventSeat;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

interface EventRepositoryInterface
{
    /**
     * @return LengthAwarePaginator<int, Event>
     */
    public function paginatePublished(EventFilter $filter): LengthAwarePaginator;

    public function findPublished(int $id): ?Event;

    public function findOwnedByOrganizer(int $id, int $organizerId): ?Event;

    /**
     * @return Collection<int, EventSeat>|null
     */
    public function seatsForPublished(int $eventId): ?Collection;

    public function persist(Event $event): void;
}
