<?php

declare(strict_types=1);

namespace App\Domain\Event\Enums;

enum SeatStatus: string
{
    case Free = 'free';
    case Sold = 'sold';
}
