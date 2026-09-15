<?php

declare(strict_types=1);

namespace App\Domain\Event\DTO;

final readonly class EventFilter
{
    public function __construct(
        public ?string $starts_from = null,
        public ?string $starts_until = null,
        public int $page = 1,
        public int $per_page = 15,
    ) {}
}
