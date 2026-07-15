<?php

declare(strict_types=1);

namespace App\Domain\Order\Services;

use App\Domain\Order\Contracts\HoldsValidatorInterface;
use App\Domain\Order\DTO\PlacedOrder;
use App\Domain\Order\DTO\PlaceOrderData;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Events\OrderPaid;
use App\Domain\Order\Events\OrderPaymentFailed;
use App\Domain\Order\Exceptions\PaymentDeclinedException;
use App\Domain\Order\Exceptions\SeatsNotHeldException;
use App\Domain\Order\Repositories\OrderRepositoryInterface;
use App\Domain\Shared\Contracts\EventPublisherInterface;
use App\Domain\Ticket\Events\TicketsIssued;

final readonly class PlaceOrderService
{
    public function __construct(
        private OrderRepositoryInterface $orders,
        private HoldsValidatorInterface $holds,
        private EventPublisherInterface $publisher,
    ) {}

    public function place(PlaceOrderData $order): PlacedOrder
    {
        $original = $this->orders->findByIdempotencyKey($order->idempotency_key, $order->buyer_id);

        if ($original !== null) {
            return new PlacedOrder($original, [], replayed: true);
        }

        $missing = $this->holds->missingSeats($order->event_id, $order->seat_ids, $order->session_id);

        if ($missing !== []) {
            throw new SeatsNotHeldException(
                'The session no longer holds every requested seat.',
                ['seats' => $missing],
            );
        }

        $placed = $this->orders->place($order);

        if ($placed->replayed) {
            return $placed;
        }

        if ($placed->order->status === OrderStatus::Paid) {
            $this->publisher->publish(new OrderPaid(
                $placed->order->id,
                $placed->order->event_id,
                $placed->order->seatIds(),
                $order->buyer_id,
            ));

            $this->publisher->publish(new TicketsIssued($placed->order->id, $placed->ticket_ids));

            return $placed;
        }

        $this->publisher->publish(new OrderPaymentFailed(
            $placed->order->id,
            $placed->order->event_id,
            $placed->order->seatIds(),
            $order->session_id,
        ));

        throw new PaymentDeclinedException('The payment for this order was declined.');
    }
}
