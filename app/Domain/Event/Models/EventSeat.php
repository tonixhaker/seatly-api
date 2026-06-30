<?php

declare(strict_types=1);

namespace App\Domain\Event\Models;

use App\Domain\Event\Enums\SeatStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * @property-read SeatStatus $status
 */
class EventSeat extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'status' => SeatStatus::class,
        ];
    }
}
