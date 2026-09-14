<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read int $id
 * @property-read string $section
 * @property-read int $row
 * @property-read int $number
 * @property-read int $x
 * @property-read int $y
 * @property-read int $price_cents
 * @property-read string $currency
 * @property-read string $status
 */
final class SeatResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'section' => $this->section,
            'row' => $this->row,
            'number' => $this->number,
            'x' => $this->x,
            'y' => $this->y,
            'price_cents' => $this->price_cents,
            'currency' => $this->currency,
            'status' => $this->status,
        ];
    }
}
