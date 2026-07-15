<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Event\Enums\SeatStatus;
use App\Domain\Order\Contracts\PaymentGatewayInterface;
use App\Domain\Order\DTO\OrderData;
use App\Domain\Order\DTO\OrderItemData;
use App\Domain\Order\DTO\PlacedOrder;
use App\Domain\Order\DTO\PlaceOrderData;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Exceptions\DuplicateIdempotencyKeyException;
use App\Domain\Order\Exceptions\MixedCurrencyOrderException;
use App\Domain\Order\Exceptions\SeatsNotHeldException;
use App\Domain\Order\Repositories\OrderRepositoryInterface;
use App\Domain\Ticket\Enums\TicketStatus;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Random\Randomizer;
use RuntimeException;

final readonly class EloquentOrderRepository implements OrderRepositoryInterface
{
    private const QR_BYTES = 6;

    private const QR_ATTEMPTS = 3;

    private const UNIQUE_VIOLATION = '23505';

    public function __construct(
        private PaymentGatewayInterface $payments,
        private Randomizer $randomizer,
    ) {}

    public function findByIdempotencyKey(string $idempotencyKey, int $buyerId): ?OrderData
    {
        $order = DB::table('orders')
            ->where('idempotency_key', $idempotencyKey)
            ->where('buyer_id', $buyerId)
            ->first();

        return $order === null ? null : self::hydrate((array) $order);
    }

    public function place(PlaceOrderData $order): PlacedOrder
    {
        return DB::transaction(function () use ($order): PlacedOrder {
            [$items, $currency] = self::lockSeats($order);

            $totalCents = array_sum(array_map(
                static fn (OrderItemData $item): int => $item->price_cents,
                $items,
            ));

            $orderId = Str::uuid()->toString();
            $replay = $this->insertPending($orderId, $order, $totalCents, $currency);

            if ($replay !== null) {
                return $replay;
            }

            DB::table('order_items')->insert(array_map(
                static fn (OrderItemData $item): array => [
                    'order_id' => $orderId,
                    'event_seat_id' => $item->event_seat_id,
                    'price_cents' => $item->price_cents,
                ],
                $items,
            ));

            $approved = $this->payments->charge($totalCents, $currency)->approved;
            $status = $approved ? OrderStatus::Paid : OrderStatus::PaymentFailed;
            $ticketIds = [];

            if ($approved) {
                DB::table('event_seats')
                    ->whereIn('id', $order->seat_ids)
                    ->update(['status' => SeatStatus::Sold->value]);

                $ticketIds = $this->issueTickets($orderId, $order->seat_ids);
            }

            DB::table('orders')
                ->where('id', $orderId)
                ->update(['status' => $status->value, 'updated_at' => now()]);

            return new PlacedOrder(
                new OrderData($orderId, $order->event_id, $status, $totalCents, $currency, $items),
                $ticketIds,
                replayed: false,
            );
        });
    }

    /**
     * @return array{list<OrderItemData>, string}
     */
    private static function lockSeats(PlaceOrderData $order): array
    {
        $rows = DB::table('event_seats')
            ->where('event_id', $order->event_id)
            ->whereIn('id', $order->seat_ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $free = [];

        foreach ($rows as $row) {
            $seat = (array) $row;

            if (self::text($seat['status'] ?? null) === SeatStatus::Free->value) {
                $free[self::number($seat['id'] ?? null)] = $seat;
            }
        }

        $unavailable = array_values(array_filter(
            $order->seat_ids,
            static fn (int $seatId): bool => ! isset($free[$seatId]),
        ));

        if ($unavailable !== []) {
            throw new SeatsNotHeldException(
                'The session no longer holds every requested seat.',
                ['seats' => $unavailable],
            );
        }

        $items = [];
        $currencies = [];

        foreach ($order->seat_ids as $seatId) {
            $items[] = new OrderItemData($seatId, self::number($free[$seatId]['price_cents'] ?? null));
            $currencies[self::text($free[$seatId]['currency'] ?? null)] = true;
        }

        if (count($currencies) !== 1) {
            throw new MixedCurrencyOrderException(
                'The requested seats are priced in more than one currency.',
                ['seat_ids' => ['An order cannot mix currencies.']],
            );
        }

        return [$items, (string) array_key_first($currencies)];
    }

    private function insertPending(string $orderId, PlaceOrderData $order, int $totalCents, string $currency): ?PlacedOrder
    {
        try {
            DB::transaction(static fn () => DB::table('orders')->insert([
                'id' => $orderId,
                'buyer_id' => $order->buyer_id,
                'event_id' => $order->event_id,
                'status' => OrderStatus::Pending->value,
                'total_cents' => $totalCents,
                'currency' => $currency,
                'idempotency_key' => $order->idempotency_key,
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        } catch (QueryException $collision) {
            if (! self::isUniqueViolation($collision)) {
                throw $collision;
            }

            $existing = $this->findByIdempotencyKey($order->idempotency_key, $order->buyer_id);

            if ($existing === null) {
                throw new DuplicateIdempotencyKeyException(
                    'This idempotency key belongs to another order.',
                    ['idempotency_key' => ['This idempotency key belongs to another order.']],
                );
            }

            return new PlacedOrder($existing, [], replayed: true);
        }

        return null;
    }

    /**
     * @param  list<int>  $seatIds
     * @return list<string>
     */
    private function issueTickets(string $orderId, array $seatIds): array
    {
        return array_map(
            fn (int $seatId): string => $this->issueTicket($orderId, $seatId),
            $seatIds,
        );
    }

    private function issueTicket(string $orderId, int $seatId): string
    {
        $ticketId = Str::uuid()->toString();

        for ($attempt = 1; $attempt <= self::QR_ATTEMPTS; $attempt++) {
            try {
                DB::transaction(fn () => DB::table('tickets')->insert([
                    'id' => $ticketId,
                    'order_id' => $orderId,
                    'event_seat_id' => $seatId,
                    'qr_code' => $this->qrCode(),
                    'status' => TicketStatus::Issued->value,
                    'checked_in_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));

                return $ticketId;
            } catch (QueryException $collision) {
                if (! self::isUniqueViolation($collision) || $attempt === self::QR_ATTEMPTS) {
                    throw $collision;
                }
            }
        }

        throw new RuntimeException('Ticket issuance exhausted its attempts.');
    }

    private function qrCode(): string
    {
        return strtoupper(bin2hex($this->randomizer->getBytes(self::QR_BYTES)));
    }

    private static function isUniqueViolation(QueryException $exception): bool
    {
        return $exception->getCode() === self::UNIQUE_VIOLATION;
    }

    /**
     * @param  array<string, mixed>  $order
     */
    private static function hydrate(array $order): OrderData
    {
        $items = DB::table('order_items')
            ->where('order_id', self::text($order['id'] ?? null))
            ->orderBy('id')
            ->get()
            ->map(static function (mixed $item): OrderItemData {
                $row = (array) $item;

                return new OrderItemData(
                    self::number($row['event_seat_id'] ?? null),
                    self::number($row['price_cents'] ?? null),
                );
            })
            ->all();

        return new OrderData(
            self::text($order['id'] ?? null),
            self::number($order['event_id'] ?? null),
            OrderStatus::from(self::text($order['status'] ?? null)),
            self::number($order['total_cents'] ?? null),
            self::text($order['currency'] ?? null),
            array_values($items),
        );
    }

    private static function number(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : throw new RuntimeException('A stored numeric column is not numeric.');
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : throw new RuntimeException('A stored text column is not a string.');
    }
}
