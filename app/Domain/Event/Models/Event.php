<?php

declare(strict_types=1);

namespace App\Domain\Event\Models;

use App\Domain\Event\Enums\EventStatus;
use App\Domain\Venue\Models\Venue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property-read Carbon $starts_at
 * @property-read EventStatus $status
 * @property-read Venue $venue
 */
class Event extends Model
{
    protected $fillable = [
        'organizer_id',
        'venue_id',
        'title',
        'description',
        'starts_at',
        'status',
    ];

    /**
     * @return BelongsTo<Venue, $this>
     */
    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'status' => EventStatus::class,
        ];
    }
}
