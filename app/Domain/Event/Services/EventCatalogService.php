<?php

declare(strict_types=1);

namespace App\Domain\Event\Services;

use App\Domain\Event\DTO\EventData;
use App\Domain\Event\DTO\EventFilter;
use App\Domain\Event\Models\Event;
use App\Domain\Event\Repositories\EventRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

final readonly class EventCatalogService
{
    public function __construct(private EventRepositoryInterface $events) {}

    /**
     * @return LengthAwarePaginator<int, EventData>
     */
    public function listPublished(EventFilter $filter): LengthAwarePaginator
    {
        $page = $this->events->paginatePublished($filter);
        $page->through(fn (Event $event): EventData => EventData::fromModel($event));

        return $page;
    }

    public function findPublished(int $id): ?EventData
    {
        $event = $this->events->findPublished($id);

        return $event === null ? null : EventData::fromModel($event);
    }
}
