<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Order\DTO\PlaceOrderData;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Exceptions\DuplicateIdempotencyKeyException;
use App\Domain\Order\Exceptions\HoldsValidationUnavailableException;
use App\Domain\Order\Exceptions\MixedCurrencyOrderException;
use App\Domain\Order\Exceptions\PaymentDeclinedException;
use App\Domain\Order\Exceptions\SeatsNotHeldException;
use App\Domain\Order\Services\PlaceOrderService;
use App\Http\Controllers\Controller;
use App\Http\Requests\PlaceOrderRequest;
use App\Http\Resources\OrderResource;
use App\Http\Resources\TicketResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @phpstan-type OrderItemFixture object{event_seat_id: int, price_cents: int}
 * @phpstan-type OrderFixture object{id: string, event_id: int, status: OrderStatus, total_cents: int, currency: string, items: list<OrderItemFixture>}
 * @phpstan-type TicketFixture object{id: string, order_id: string, event_seat_id: int, qr_code: string, status: string, checked_in_at: string|null}
 */
final class OrderController extends Controller
{
    private const ORDER_ID = '3f1b8c42-5d6e-4a7b-9c10-2e4f6a8b0d13';

    public function __construct(private readonly PlaceOrderService $orders) {}

    /**
     * Place an order for the seats this session holds.
     *
     * @throws AccessDeniedHttpException
     * @throws SeatsNotHeldException
     * @throws PaymentDeclinedException
     * @throws DuplicateIdempotencyKeyException
     * @throws MixedCurrencyOrderException
     * @throws HoldsValidationUnavailableException
     */
    public function store(PlaceOrderRequest $request): JsonResponse
    {
        $placed = $this->orders->place(new PlaceOrderData(
            self::buyerId($request),
            $request->integer('event_id'),
            array_values(array_map(
                static fn (mixed $seatId): int => is_numeric($seatId) ? (int) $seatId : 0,
                (array) $request->validated('seat_ids'),
            )),
            $request->string('session_id')->toString(),
            $request->string('idempotency_key')->toString(),
        ));

        if ($placed->replayed) {
            return (new OrderResource($placed->order))->response()->setStatusCode(200);
        }

        return (new OrderResource($placed->order))->response()->setStatusCode(201);
    }

    private static function buyerId(PlaceOrderRequest $request): int
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_numeric($id) ? (int) $id : throw new RuntimeException('The authenticated buyer has no numeric identifier.');
    }

    /**
     * Return one of the buyer's own orders.
     *
     * @throws AccessDeniedHttpException
     * @throws NotFoundHttpException
     */
    public function show(string $id): OrderResource
    {
        if ($id !== self::ORDER_ID) {
            abort(404);
        }

        return new OrderResource(self::orderFixture());
    }

    /**
     * List the buyer's tickets.
     *
     * @throws AccessDeniedHttpException
     */
    public function tickets(): AnonymousResourceCollection
    {
        return TicketResource::collection(self::ticketFixtures());
    }

    /**
     * @return OrderFixture
     */
    private static function orderFixture(): object
    {
        return (object) [
            'id' => self::ORDER_ID,
            'event_id' => 1,
            'status' => OrderStatus::Paid,
            'total_cents' => 10000,
            'currency' => 'EUR',
            'items' => [
                (object) ['event_seat_id' => 1, 'price_cents' => 5000],
                (object) ['event_seat_id' => 2, 'price_cents' => 5000],
            ],
        ];
    }

    /**
     * @return list<TicketFixture>
     */
    private static function ticketFixtures(): array
    {
        return [
            (object) [
                'id' => 'a1d4e7f0-2b58-4c91-8d3e-6f07a9b2c4d5',
                'order_id' => self::ORDER_ID,
                'event_seat_id' => 1,
                'qr_code' => 'A1B2C3D4E5F6',
                'status' => 'issued',
                'checked_in_at' => null,
            ],
            (object) [
                'id' => 'b2e5f801-3c69-4da2-9e4f-7008bac3d5e6',
                'order_id' => self::ORDER_ID,
                'event_seat_id' => 2,
                'qr_code' => 'G7H8J9K0L1M2',
                'status' => 'checked_in',
                'checked_in_at' => '2026-10-01T18:42:07Z',
            ],
        ];
    }
}
