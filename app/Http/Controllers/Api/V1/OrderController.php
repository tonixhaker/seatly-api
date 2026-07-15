<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Order\DTO\PlaceOrderData;
use App\Domain\Order\Exceptions\DuplicateIdempotencyKeyException;
use App\Domain\Order\Exceptions\HoldsValidationUnavailableException;
use App\Domain\Order\Exceptions\MixedCurrencyOrderException;
use App\Domain\Order\Exceptions\PaymentDeclinedException;
use App\Domain\Order\Exceptions\SeatsNotHeldException;
use App\Domain\Order\Services\OrderReadService;
use App\Domain\Order\Services\PlaceOrderService;
use App\Http\Controllers\Controller;
use App\Http\Requests\PlaceOrderRequest;
use App\Http\Resources\OrderResource;
use App\Http\Resources\TicketResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class OrderController extends Controller
{
    public function __construct(
        private readonly PlaceOrderService $orders,
        private readonly OrderReadService $reader,
    ) {}

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

    private static function buyerId(Request $request): int
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
    public function show(Request $request, string $id): OrderResource
    {
        $order = $this->reader->findOwnedByBuyer($id, self::buyerId($request));

        if ($order === null) {
            abort(404);
        }

        return new OrderResource($order);
    }

    /**
     * List the buyer's tickets.
     *
     * @throws AccessDeniedHttpException
     */
    public function tickets(Request $request): AnonymousResourceCollection
    {
        return TicketResource::collection($this->reader->ticketsForBuyer(self::buyerId($request)));
    }
}
