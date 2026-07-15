<?php

declare(strict_types=1);

use App\Domain\Order\Contracts\HoldsValidatorInterface;
use App\Domain\Order\DTO\OrderData;
use App\Domain\Order\DTO\OrderItemData;
use App\Domain\Order\DTO\PlacedOrder;
use App\Domain\Order\DTO\PlaceOrderData;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Exceptions\HoldsValidationUnavailableException;
use App\Domain\Order\Exceptions\PaymentDeclinedException;
use App\Domain\Order\Exceptions\SeatsNotHeldException;
use App\Domain\Order\Repositories\OrderRepositoryInterface;
use App\Domain\Order\Services\PlaceOrderService;
use App\Domain\Shared\Contracts\DomainEvent;
use App\Domain\Shared\Contracts\EventPublisherInterface;
use App\Domain\Ticket\DTO\TicketData;

$orderData = function (OrderStatus $status = OrderStatus::Paid): OrderData {
    return new OrderData(
        'f47ac10b-58cc-4372-a567-0e02b2c3d479',
        42,
        $status,
        14600,
        'EUR',
        [new OrderItemData(11, 7300), new OrderItemData(12, 7300)],
    );
};

$request = fn (): PlaceOrderData => new PlaceOrderData(
    7,
    42,
    [11, 12],
    '11111111-2222-4333-8444-555555555555',
    'key-abc',
);

$repository = function (?OrderData $replay, ?PlacedOrder $placed): OrderRepositoryInterface {
    return new class($replay, $placed) implements OrderRepositoryInterface
    {
        public int $lookups = 0;

        public int $places = 0;

        public function __construct(private readonly ?OrderData $replay, private readonly ?PlacedOrder $placed) {}

        public function findByIdempotencyKey(string $idempotencyKey, int $buyerId): ?OrderData
        {
            $this->lookups++;

            return $this->replay;
        }

        public function findOwnedByBuyer(string $orderId, int $buyerId): ?OrderData
        {
            return null;
        }

        /**
         * @return list<TicketData>
         */
        public function ticketsForBuyer(int $buyerId): array
        {
            return [];
        }

        public function place(PlaceOrderData $order): PlacedOrder
        {
            $this->places++;

            return $this->placed ?? throw new LogicException('place() was not expected on this path.');
        }
    };
};

$validator = function (array $missing, ?Throwable $throws = null): HoldsValidatorInterface {
    return new class($missing, $throws) implements HoldsValidatorInterface
    {
        public int $calls = 0;

        /** @var list<array{int, list<int>, string}> */
        public array $received = [];

        public function __construct(private readonly array $missing, private readonly ?Throwable $throws) {}

        public function missingSeats(int $eventId, array $seatIds, string $sessionId): array
        {
            $this->calls++;
            $this->received[] = [$eventId, $seatIds, $sessionId];

            if ($this->throws !== null) {
                throw $this->throws;
            }

            return $this->missing;
        }
    };
};

$publisher = function (): EventPublisherInterface {
    return new class implements EventPublisherInterface
    {
        /** @var list<DomainEvent> */
        public array $published = [];

        public function publish(DomainEvent $event): void
        {
            $this->published[] = $event;
        }
    };
};

it('short-circuits a replay without asking realtime, placing, or publishing', function () use ($orderData, $request, $repository, $validator, $publisher): void {
    $orders = $repository($orderData(), null);
    $holds = $validator([]);
    $events = $publisher();

    $placed = (new PlaceOrderService($orders, $holds, $events))->place($request());

    expect($placed->replayed)->toBeTrue()
        ->and($placed->ticket_ids)->toBe([])
        ->and($placed->order->id)->toBe('f47ac10b-58cc-4372-a567-0e02b2c3d479')
        ->and($holds->calls)->toBe(0)
        ->and($orders->places)->toBe(0)
        ->and($events->published)->toBe([]);
});

it('passes the buyer event, seats and session straight through to the validator', function () use ($request, $repository, $validator, $publisher, $orderData): void {
    $orders = $repository(null, new PlacedOrder($orderData(), ['t1', 't2'], false));
    $holds = $validator([]);

    (new PlaceOrderService($orders, $holds, $publisher()))->place($request());

    expect($holds->received)->toBe([[42, [11, 12], '11111111-2222-4333-8444-555555555555']]);
});

it('reports the missing seats verbatim, in the order realtime named them', function () use ($request, $repository, $validator, $publisher): void {
    $orders = $repository(null, null);
    $holds = $validator([12, 11]);

    expect(fn () => (new PlaceOrderService($orders, $holds, $publisher()))->place($request()))
        ->toThrow(SeatsNotHeldException::class);

    try {
        (new PlaceOrderService($orders, $holds, $publisher()))->place($request());
    } catch (SeatsNotHeldException $refusal) {
        expect($refusal->details)->toBe(['seats' => [12, 11]]);
    }

    expect($orders->places)->toBe(0);
});

it('lets an unavailable validator escape rather than blaming the buyer', function () use ($request, $repository, $validator, $publisher): void {
    $orders = $repository(null, null);
    $holds = $validator([], new HoldsValidationUnavailableException('Seat holds could not be verified.'));

    expect(fn () => (new PlaceOrderService($orders, $holds, $publisher()))->place($request()))
        ->toThrow(HoldsValidationUnavailableException::class);

    expect($orders->places)->toBe(0);
});

it('publishes order.paid then tickets.issued with the frozen payloads', function () use ($orderData, $request, $repository, $validator, $publisher): void {
    $orders = $repository(null, new PlacedOrder($orderData(), ['t-1', 't-2'], false));
    $events = $publisher();

    (new PlaceOrderService($orders, $validator([]), $events))->place($request());

    expect(array_map(fn (DomainEvent $event): string => $event->type(), $events->published))
        ->toBe(['order.paid', 'tickets.issued'])
        ->and($events->published[0]->payload())->toBe([
            'order_id' => 'f47ac10b-58cc-4372-a567-0e02b2c3d479',
            'event_id' => 42,
            'seat_ids' => [11, 12],
            'buyer_id' => 7,
        ])
        ->and($events->published[1]->payload())->toBe([
            'order_id' => 'f47ac10b-58cc-4372-a567-0e02b2c3d479',
            'ticket_ids' => ['t-1', 't-2'],
        ]);
});

it('publishes the failure before it throws, and publishes nothing else', function () use ($orderData, $request, $repository, $validator, $publisher): void {
    $orders = $repository(null, new PlacedOrder($orderData(OrderStatus::PaymentFailed), [], false));
    $events = $publisher();
    $service = new PlaceOrderService($orders, $validator([]), $events);

    expect(fn () => $service->place($request()))->toThrow(PaymentDeclinedException::class);

    expect(array_map(fn (DomainEvent $event): string => $event->type(), $events->published))
        ->toBe(['order.payment_failed'])
        ->and($events->published[0]->payload())->toBe([
            'order_id' => 'f47ac10b-58cc-4372-a567-0e02b2c3d479',
            'event_id' => 42,
            'seat_ids' => [11, 12],
            'session_id' => '11111111-2222-4333-8444-555555555555',
        ]);
});

it('publishes nothing when the repository itself answers a replay', function () use ($orderData, $request, $repository, $validator, $publisher): void {
    $orders = $repository(null, new PlacedOrder($orderData(), [], true));
    $events = $publisher();

    $placed = (new PlaceOrderService($orders, $validator([]), $events))->place($request());

    expect($placed->replayed)->toBeTrue()->and($events->published)->toBe([]);
});
