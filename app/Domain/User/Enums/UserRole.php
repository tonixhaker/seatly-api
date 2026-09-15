<?php

declare(strict_types=1);

namespace App\Domain\User\Enums;

enum UserRole: string
{
    case Buyer = 'buyer';
    case Organizer = 'organizer';
}
