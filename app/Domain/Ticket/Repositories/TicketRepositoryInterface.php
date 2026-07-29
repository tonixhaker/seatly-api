<?php

declare(strict_types=1);

namespace App\Domain\Ticket\Repositories;

use App\Domain\Ticket\DTO\TicketData;

interface TicketRepositoryInterface
{
    public function findForOrganizer(string $qrCode, int $organizerId): ?TicketData;

    public function markCheckedIn(string $ticketId): bool;
}
