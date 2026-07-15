<?php

declare(strict_types=1);

namespace App\Domain\Order\Repositories;

use App\Domain\Order\DTO\OrderData;
use App\Domain\Order\DTO\PlacedOrder;
use App\Domain\Order\DTO\PlaceOrderData;
use App\Domain\Order\Exceptions\DuplicateIdempotencyKeyException;
use App\Domain\Order\Exceptions\MixedCurrencyOrderException;
use App\Domain\Order\Exceptions\SeatsNotHeldException;
use App\Domain\Ticket\DTO\TicketData;

interface OrderRepositoryInterface
{
    public function findByIdempotencyKey(string $idempotencyKey, int $buyerId): ?OrderData;

    public function findOwnedByBuyer(string $orderId, int $buyerId): ?OrderData;

    /**
     * @return list<TicketData>
     */
    public function ticketsForBuyer(int $buyerId): array;

    /**
     * @throws SeatsNotHeldException
     * @throws DuplicateIdempotencyKeyException
     * @throws MixedCurrencyOrderException
     */
    public function place(PlaceOrderData $order): PlacedOrder;
}
