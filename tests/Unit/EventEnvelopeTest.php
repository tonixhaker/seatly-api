<?php

declare(strict_types=1);

use App\Domain\Event\Events\EventPublished;
use App\Domain\Shared\Contracts\DomainEvent;
use App\Infrastructure\Messaging\EventEnvelope;

$anyEvent = function (string $type, array $payload): DomainEvent {
    return new class($type, $payload) implements DomainEvent
    {
        /**
         * @param  array<string, mixed>  $payload
         */
        public function __construct(private readonly string $type, private readonly array $payload) {}

        public function type(): string
        {
            return $this->type;
        }

        /**
         * @return array<string, mixed>
         */
        public function payload(): array
        {
            return $this->payload;
        }
    };
};

it('carries exactly the five envelope fields the frozen contract names', function (): void {
    $envelope = EventEnvelope::for(new EventPublished(7, [1, 2, 3]));

    expect(array_keys($envelope->toArray()))
        ->toBe(['event_id', 'event_type', 'occurred_at', 'version', 'payload']);
});

it('gives the message its own uuid identity, distinct from the ticketed event id in the payload', function (): void {
    $first = EventEnvelope::for(new EventPublished(7, [1, 2, 3]));
    $second = EventEnvelope::for(new EventPublished(7, [1, 2, 3]));

    expect($first->event_id)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/')
        ->and($first->event_id)->not->toBe($second->event_id)
        ->and($first->event_id)->not->toBe('7')
        ->and($first->payload['event_id'])->toBe(7);
});

it('stamps occurred_at as Zulu time, because the consumer rejects an offset-less timestamp', function (): void {
    $envelope = EventEnvelope::for(new EventPublished(7, [1]));

    expect($envelope->occurred_at)->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/')
        ->and($envelope->occurred_at)->toEndWith('Z')
        ->and($envelope->occurred_at)->not->toContain('+');
});

it('pins version at one, which every event schema declares as a constant', function (): void {
    expect(EventEnvelope::for(new EventPublished(7, [1]))->version)->toBe(1);
});

it('routes on the event type itself, for every one of the four frozen types', function (string $type) use ($anyEvent): void {
    $envelope = EventEnvelope::for($anyEvent($type, ['whatever' => 1]));

    expect($envelope->routingKey())->toBe($type)
        ->and($envelope->routingKey())->toBe($envelope->event_type);
})->with(['event.published', 'order.paid', 'order.payment_failed', 'tickets.issued']);

it('serialises to json that decodes back to the same five fields', function (): void {
    $envelope = EventEnvelope::for(new EventPublished(7, [1, 2]));

    expect(json_decode($envelope->toJson(), true))->toBe($envelope->toArray());
});
