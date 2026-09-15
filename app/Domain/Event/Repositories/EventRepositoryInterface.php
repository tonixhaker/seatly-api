<?php

declare(strict_types=1);

namespace App\Domain\Event\Repositories;

use App\Domain\Event\DTO\EventFilter;
use App\Domain\Event\Models\Event;
use Illuminate\Pagination\LengthAwarePaginator;

interface EventRepositoryInterface
{
    /**
     * @return LengthAwarePaginator<int, Event>
     */
    public function paginatePublished(EventFilter $filter): LengthAwarePaginator;

    public function findPublished(int $id): ?Event;

    public function findOwnedByOrganizer(int $id, int $organizerId): ?Event;

    public function persist(Event $event): void;
}
