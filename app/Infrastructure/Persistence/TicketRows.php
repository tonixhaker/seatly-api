<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Ticket\DTO\TicketData;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class TicketRows
{
    public const COLUMNS = [
        'tickets.id',
        'tickets.order_id',
        'tickets.event_seat_id',
        'tickets.qr_code',
        'tickets.status',
        'tickets.checked_in_at',
        'event_seats.event_id as event_id',
        'events.title as event_title',
        'events.starts_at as event_starts_at',
        'event_seats.section as seat_section',
        'event_seats.row as seat_row',
        'event_seats.number as seat_number',
    ];

    public static function query(): Builder
    {
        return DB::table('tickets')
            ->join('event_seats', 'event_seats.id', '=', 'tickets.event_seat_id')
            ->join('events', 'events.id', '=', 'event_seats.event_id');
    }

    public static function toData(mixed $ticket): TicketData
    {
        $row = (array) $ticket;

        return new TicketData(
            StoredColumn::text($row['id'] ?? null),
            StoredColumn::text($row['order_id'] ?? null),
            StoredColumn::number($row['event_seat_id'] ?? null),
            StoredColumn::text($row['qr_code'] ?? null),
            StoredColumn::text($row['status'] ?? null),
            StoredColumn::timestamp($row['checked_in_at'] ?? null),
            StoredColumn::number($row['event_id'] ?? null),
            StoredColumn::text($row['event_title'] ?? null),
            StoredColumn::text(StoredColumn::timestamp($row['event_starts_at'] ?? null)),
            StoredColumn::text($row['seat_section'] ?? null),
            StoredColumn::number($row['seat_row'] ?? null),
            StoredColumn::number($row['seat_number'] ?? null),
        );
    }
}
