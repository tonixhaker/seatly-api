<?php

declare(strict_types=1);

namespace App\Domain\Order\Services;

use App\Domain\Order\DTO\OrderData;
use App\Domain\Order\Repositories\OrderRepositoryInterface;
use App\Domain\Ticket\DTO\TicketData;

final readonly class OrderReadService
{
    public function __construct(private OrderRepositoryInterface $orders) {}

    public function findOwnedByBuyer(string $orderId, int $buyerId): ?OrderData
    {
        return $this->orders->findOwnedByBuyer($orderId, $buyerId);
    }

    /**
     * @return list<TicketData>
     */
    public function ticketsForBuyer(int $buyerId): array
    {
        return $this->orders->ticketsForBuyer($buyerId);
    }
}
