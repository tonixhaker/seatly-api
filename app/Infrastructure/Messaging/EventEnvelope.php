<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

use App\Domain\Shared\Contracts\DomainEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final readonly class EventEnvelope
{
    public const VERSION = 1;

    /**
     * @param  array<string, mixed>  $payload
     */
    private function __construct(
        public string $event_id,
        public string $event_type,
        public string $occurred_at,
        public int $version,
        public array $payload,
    ) {}

    public static function for(DomainEvent $event): self
    {
        return new self(
            Str::uuid()->toString(),
            $event->type(),
            CarbonImmutable::now()->toIso8601ZuluString(),
            self::VERSION,
            $event->payload(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'event_id' => $this->event_id,
            'event_type' => $this->event_type,
            'occurred_at' => $this->occurred_at,
            'version' => $this->version,
            'payload' => $this->payload,
        ];
    }

    public function toJson(): string
    {
        return (string) json_encode($this->toArray(), JSON_THROW_ON_ERROR);
    }

    public function routingKey(): string
    {
        return $this->event_type;
    }
}
