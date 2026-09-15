<?php

declare(strict_types=1);

namespace App\Domain\Event\DTO;

use App\Domain\Event\Enums\SeatStatus;
use App\Domain\Event\Models\EventSeat;

final readonly class SeatData
{
    public function __construct(
        public int $id,
        public string $section,
        public int $row,
        public int $number,
        public int $x,
        public int $y,
        public int $price_cents,
        public string $currency,
        public SeatStatus $status,
    ) {}

    public static function fromModel(EventSeat $seat): self
    {
        return new self(
            id: $seat->id,
            section: $seat->section,
            row: $seat->row,
            number: $seat->number,
            x: $seat->x,
            y: $seat->y,
            price_cents: $seat->price_cents,
            currency: $seat->currency,
            status: $seat->status,
        );
    }
}
