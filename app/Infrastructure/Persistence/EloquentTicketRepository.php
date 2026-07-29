<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Ticket\DTO\TicketData;
use App\Domain\Ticket\Enums\TicketStatus;
use App\Domain\Ticket\Repositories\TicketRepositoryInterface;
use Illuminate\Support\Facades\DB;

final readonly class EloquentTicketRepository implements TicketRepositoryInterface
{
    public function findForOrganizer(string $qrCode, int $organizerId): ?TicketData
    {
        $ticket = TicketRows::query()
            ->where('tickets.qr_code', $qrCode)
            ->where('events.organizer_id', $organizerId)
            ->first(TicketRows::COLUMNS);

        return $ticket === null ? null : TicketRows::toData($ticket);
    }

    public function markCheckedIn(string $ticketId): bool
    {
        return DB::table('tickets')
            ->where('id', $ticketId)
            ->where('status', TicketStatus::Issued->value)
            ->update([
                'status' => TicketStatus::CheckedIn->value,
                'checked_in_at' => now('UTC'),
                'updated_at' => now(),
            ]) === 1;
    }
}
