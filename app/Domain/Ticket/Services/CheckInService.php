<?php

declare(strict_types=1);

namespace App\Domain\Ticket\Services;

use App\Domain\Ticket\DTO\TicketData;
use App\Domain\Ticket\Exceptions\AlreadyCheckedInException;
use App\Domain\Ticket\Repositories\TicketRepositoryInterface;

final readonly class CheckInService
{
    public function __construct(private TicketRepositoryInterface $tickets) {}

    /**
     * @throws AlreadyCheckedInException
     */
    public function checkIn(string $qrCode, int $organizerId): ?TicketData
    {
        $ticket = $this->tickets->findForOrganizer($qrCode, $organizerId);

        if ($ticket === null) {
            return null;
        }

        $won = $this->tickets->markCheckedIn($ticket->id);
        $current = $this->tickets->findForOrganizer($qrCode, $organizerId);

        if ($current === null || $won) {
            return $current;
        }

        throw new AlreadyCheckedInException(
            'This ticket was already checked in.',
            ['checked_in_at' => $current->checked_in_at],
        );
    }
}
