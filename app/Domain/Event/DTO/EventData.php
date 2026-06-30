<?php

declare(strict_types=1);

namespace App\Domain\Event\DTO;

use App\Domain\Event\Enums\EventStatus;
use App\Domain\Event\Models\Event;
use App\Domain\Venue\DTO\VenueData;

final readonly class EventData
{
    public function __construct(
        public int $id,
        public string $title,
        public string $description,
        public string $starts_at,
        public EventStatus $status,
        public VenueData $venue,
    ) {}

    public static function fromModel(Event $event): self
    {
        return new self(
            id: $event->id,
            title: $event->title,
            description: $event->description ?? '',
            starts_at: $event->starts_at->toIso8601ZuluString(),
            status: $event->status,
            venue: VenueData::fromModel($event->venue),
        );
    }
}
