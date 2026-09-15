<?php

declare(strict_types=1);

namespace App\Domain\Event\DTO;

final readonly class EventDraftData
{
    public function __construct(
        public int $venue_id,
        public string $title,
        public ?string $description,
        public string $starts_at,
    ) {}
}
