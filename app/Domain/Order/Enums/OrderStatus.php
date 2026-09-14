<?php

declare(strict_types=1);

namespace App\Domain\Order\Enums;

enum OrderStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case PaymentFailed = 'payment_failed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
}
