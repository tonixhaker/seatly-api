<?php

declare(strict_types=1);

use App\Domain\Shared\Contracts\DomainEvent;
use App\Infrastructure\Messaging\EnvelopeSchemaValidator;
use App\Infrastructure\Messaging\EventEnvelope;
use App\Infrastructure\Messaging\InvalidEventEnvelopeException;

$schemaDirectory = dirname(__DIR__, 2).'/docs/events';

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

$payloads = [
    'event.published' => ['event_id' => 1, 'seat_ids' => [1, 2, 3]],
    'order.paid' => [
        'order_id' => '7b1f2a3c-4d5e-4f60-8a1b-2c3d4e5f6a7b',
        'event_id' => 1,
        'seat_ids' => [3, 7, 11],
        'buyer_id' => 42,
    ],
    'order.payment_failed' => [
        'order_id' => '7b1f2a3c-4d5e-4f60-8a1b-2c3d4e5f6a7b',
        'event_id' => 1,
        'seat_ids' => [3, 7, 11],
        'session_id' => 'c3d4e5f6-a7b8-4901-9234-5f6a7b8c9d0e',
    ],
    'tickets.issued' => [
        'order_id' => '7b1f2a3c-4d5e-4f60-8a1b-2c3d4e5f6a7b',
        'ticket_ids' => ['9a1c7d40-2f38-4c9b-b0e5-6a7f8b9c0d1e'],
    ],
];

it('accepts a message built by the producer against the schema file on disk, for every frozen type', function (string $type) use ($schemaDirectory, $anyEvent, $payloads): void {
    $validator = new EnvelopeSchemaValidator($schemaDirectory);
    $envelope = EventEnvelope::for($anyEvent($type, $payloads[$type]));

    expect(fn (): mixed => $validator->assertValid($envelope))
        ->not->toThrow(InvalidEventEnvelopeException::class);
})->with(array_keys($payloads));

it('rejects an extra payload field before anything is published, for every frozen type', function (string $type) use ($schemaDirectory, $anyEvent, $payloads): void {
    $validator = new EnvelopeSchemaValidator($schemaDirectory);
    $envelope = EventEnvelope::for($anyEvent($type, $payloads[$type] + ['sneaky' => true]));

    expect(fn (): mixed => $validator->assertValid($envelope))
        ->toThrow(InvalidEventEnvelopeException::class, $type);
})->with(array_keys($payloads));

it('rejects a payload missing a field the schema requires', function (string $type, string $field) use ($schemaDirectory, $anyEvent, $payloads): void {
    $payload = $payloads[$type];
    unset($payload[$field]);

    $validator = new EnvelopeSchemaValidator($schemaDirectory);

    expect(fn (): mixed => $validator->assertValid(EventEnvelope::for($anyEvent($type, $payload))))
        ->toThrow(InvalidEventEnvelopeException::class);
})->with([
    'event.published without seat_ids' => ['event.published', 'seat_ids'],
    'order.paid without buyer_id' => ['order.paid', 'buyer_id'],
    'order.payment_failed without session_id' => ['order.payment_failed', 'session_id'],
    'tickets.issued without ticket_ids' => ['tickets.issued', 'ticket_ids'],
]);

it('rejects a payload whose field carries the wrong type', function () use ($schemaDirectory, $anyEvent): void {
    $validator = new EnvelopeSchemaValidator($schemaDirectory);
    $envelope = EventEnvelope::for($anyEvent('event.published', ['event_id' => 'one', 'seat_ids' => [1]]));

    expect(fn (): mixed => $validator->assertValid($envelope))
        ->toThrow(InvalidEventEnvelopeException::class);
});

it('refuses an event type no schema is published for, instead of falling back to the open envelope', function () use ($schemaDirectory, $anyEvent): void {
    $validator = new EnvelopeSchemaValidator($schemaDirectory);
    $envelope = EventEnvelope::for($anyEvent('order.refunded', ['order_id' => 'x']));

    expect(fn (): mixed => $validator->assertValid($envelope))
        ->toThrow(InvalidEventEnvelopeException::class, 'order.refunded');
});

it('resolves the cross-file reference from disk and never over the network', function () use ($anyEvent, $payloads): void {
    $lonely = sys_get_temp_dir().'/seatly-schema-'.bin2hex(random_bytes(6));
    mkdir($lonely);
    copy(dirname(__DIR__, 2).'/docs/events/event.published.json', $lonely.'/event.published.json');

    $validator = new EnvelopeSchemaValidator($lonely);
    $envelope = EventEnvelope::for($anyEvent('event.published', $payloads['event.published']));

    $thrown = null;

    try {
        $validator->assertValid($envelope);
    } catch (Throwable $failure) {
        $thrown = $failure;
    }

    unlink($lonely.'/event.published.json');
    rmdir($lonely);

    expect($thrown)->not->toBeNull()
        ->and($thrown::class)->not->toBe(InvalidEventEnvelopeException::class);
});
