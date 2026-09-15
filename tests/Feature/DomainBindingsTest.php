<?php

declare(strict_types=1);

use App\Domain\Event\Contracts\EventPublisherInterface;
use App\Domain\Event\DTO\EventFilter;
use App\Domain\Event\Enums\EventStatus;
use App\Domain\Event\Models\Event;
use App\Domain\Event\Repositories\EventRepositoryInterface;
use App\Domain\Event\Services\EventCatalogService;
use App\Domain\Event\Services\EventDraftService;
use App\Domain\Event\Services\EventPublishService;
use App\Domain\User\Repositories\UserRepositoryInterface;
use App\Domain\Venue\Models\Venue;
use App\Domain\Venue\Repositories\VenueRepositoryInterface;
use App\Infrastructure\Messaging\LoggingEventPublisher;
use App\Infrastructure\Persistence\EloquentEventRepository;
use App\Infrastructure\Persistence\EloquentUserRepository;
use App\Infrastructure\Persistence\EloquentVenueRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;

uses(RefreshDatabase::class);

it('resolves every domain interface through the container', function (string $interface, string $implementation): void {
    expect(app($interface))->toBeInstanceOf($implementation);
})->with([
    'events' => [EventRepositoryInterface::class, EloquentEventRepository::class],
    'venues' => [VenueRepositoryInterface::class, EloquentVenueRepository::class],
    'users' => [UserRepositoryInterface::class, EloquentUserRepository::class],
    'event publisher' => [EventPublisherInterface::class, LoggingEventPublisher::class],
]);

it('autowires the catalog service from the interface binding alone', function (): void {
    expect(app(EventCatalogService::class))->toBeInstanceOf(EventCatalogService::class);
});

it('autowires the draft service from both repository bindings alone', function (): void {
    expect(app(EventDraftService::class))->toBeInstanceOf(EventDraftService::class);
});

it('autowires the publish service from the repository and publisher bindings alone', function (): void {
    expect(app(EventPublishService::class))->toBeInstanceOf(EventPublishService::class);
});

it('changes the service behaviour when the implementation binding is swapped', function (): void {
    expect(app(EventCatalogService::class)->findPublished(1))->toBeNull();

    app()->bind(EventRepositoryInterface::class, function (): EventRepositoryInterface {
        return new class implements EventRepositoryInterface
        {
            public function paginatePublished(EventFilter $filter): LengthAwarePaginator
            {
                return new LengthAwarePaginator([], 0, 15, 1);
            }

            public function findPublished(int $id): ?Event
            {
                $venue = new Venue;
                $venue->setRawAttributes(['id' => 1, 'name' => 'Swapped Arena', 'address' => 'Nowhere', 'city' => 'Nowhere']);

                $event = new Event;
                $event->setRawAttributes([
                    'id' => $id,
                    'title' => 'Swapped In',
                    'description' => null,
                    'starts_at' => now(),
                    'status' => EventStatus::Published->value,
                ]);
                $event->setRelation('venue', $venue);

                return $event;
            }

            public function findOwnedByOrganizer(int $id, int $organizerId): ?Event
            {
                return null;
            }

            public function seatsForPublished(int $eventId): ?Collection
            {
                return null;
            }

            public function publishWithSeats(Event $event, array $seats): ?array
            {
                return null;
            }

            public function persist(Event $event): void {}
        };
    });

    expect(app(EventCatalogService::class)->findPublished(1)?->title)->toBe('Swapped In');
});
