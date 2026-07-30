<?php

declare(strict_types=1);

use App\Domain\Event\DTO\EventFilter;
use App\Domain\Event\Enums\EventStatus;
use App\Domain\Event\Models\Event;
use App\Domain\Event\Repositories\EventRepositoryInterface;
use App\Domain\Event\Services\EventCatalogService;
use App\Domain\Event\Services\EventDraftService;
use App\Domain\Event\Services\EventPublishService;
use App\Domain\Order\Contracts\HoldsValidatorInterface;
use App\Domain\Order\Repositories\OrderRepositoryInterface;
use App\Domain\Shared\Contracts\EventPublisherInterface;
use App\Domain\Ticket\Repositories\TicketRepositoryInterface;
use App\Domain\Ticket\Services\CheckInService;
use App\Domain\User\Repositories\UserRepositoryInterface;
use App\Domain\Venue\Models\Venue;
use App\Domain\Venue\Repositories\VenueRepositoryInterface;
use App\Infrastructure\Messaging\LoggingEventPublisher;
use App\Infrastructure\Persistence\EloquentEventRepository;
use App\Infrastructure\Persistence\EloquentOrderRepository;
use App\Infrastructure\Persistence\EloquentTicketRepository;
use App\Infrastructure\Persistence\EloquentUserRepository;
use App\Infrastructure\Persistence\EloquentVenueRepository;
use App\Infrastructure\Realtime\HttpHoldsValidator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use ReflectionProperty;

uses(RefreshDatabase::class);

it('resolves every domain interface through the container', function (string $interface, string $implementation): void {
    expect(app($interface))->toBeInstanceOf($implementation);
})->with([
    'events' => [EventRepositoryInterface::class, EloquentEventRepository::class],
    'venues' => [VenueRepositoryInterface::class, EloquentVenueRepository::class],
    'users' => [UserRepositoryInterface::class, EloquentUserRepository::class],
    'holds validator' => [HoldsValidatorInterface::class, HttpHoldsValidator::class],
    'orders' => [OrderRepositoryInterface::class, EloquentOrderRepository::class],
    'event publisher' => [EventPublisherInterface::class, LoggingEventPublisher::class],
    'tickets' => [TicketRepositoryInterface::class, EloquentTicketRepository::class],
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

it('autowires the check-in service from the ticket repository binding alone', function (): void {
    expect(app(CheckInService::class))->toBeInstanceOf(CheckInService::class);
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

            public function paginateOwnedByOrganizer(int $organizerId, EventFilter $filter): LengthAwarePaginator
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

            public function statsForOrganizer(int $id, int $organizerId): ?array
            {
                return null;
            }

            public function persist(Event $event): void {}
        };
    });

    expect(app(EventCatalogService::class)->findPublished(1)?->title)->toBe('Swapped In');
});

it('wires the configured realtime url, token and timeout into the bound validator', function (): void {
    config([
        'realtime.base_url' => 'http://realtime.example:3000',
        'realtime.internal_token' => 'a-configured-token',
        'realtime.timeout_seconds' => 3.5,
    ]);
    app()->forgetInstance(HoldsValidatorInterface::class);

    $validator = app(HoldsValidatorInterface::class);

    $read = static fn (string $property): mixed => (new ReflectionProperty(HttpHoldsValidator::class, $property))->getValue($validator);

    expect($read('baseUrl'))->toBe('http://realtime.example:3000')
        ->and($read('internalToken'))->toBe('a-configured-token')
        ->and($read('timeoutSeconds'))->toBe(3.5);
});

it('defaults the checkout timeout to two seconds, shorter than the payment delay', function (): void {
    expect(require base_path('config/realtime.php'))
        ->toMatchArray(['timeout_seconds' => 2.0]);
});
