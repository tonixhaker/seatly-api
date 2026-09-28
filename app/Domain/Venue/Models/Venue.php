<?php

declare(strict_types=1);

namespace App\Domain\Venue\Models;

use Illuminate\Database\Eloquent\Model;

class Venue extends Model
{
    protected $fillable = [
        'name',
        'address',
        'city',
        'seat_map_template',
    ];

    protected function casts(): array
    {
        return [
            'seat_map_template' => 'array',
        ];
    }
}
