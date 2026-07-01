<?php

declare(strict_types=1);

namespace App\Domain\Event\DTO;

final readonly class SeatBlueprint
{
    public function __construct(
        public string $section,
        public int $row,
        public int $number,
        public int $x,
        public int $y,
        public int $price_cents,
        public string $currency,
    ) {}
}
