<?php

declare(strict_types=1);

namespace App\Domain\Ticket\Enums;

enum TicketStatus: string
{
    case Issued = 'issued';
    case CheckedIn = 'checked_in';
}
