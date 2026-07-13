<?php

declare(strict_types=1);

use App\Domain\Event\Events\EventPublished;
use App\Infrastructure\Messaging\EventEnvelope;

$contractDirectory = dirname(__DIR__, 2).'/docs/events';

$readSchema = function (string $file): array {
    $decoded = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

    return is_array($decoded) ? $decoded : [];
};

$frozenTypes = ['event.published', 'order.paid', 'order.payment_failed', 'tickets.issued'];

it('publishes a schema file for exactly the four event types the envelope enum allows', function () use ($contractDirectory, $readSchema, $frozenTypes): void {
    $enum = $readSchema($contractDirectory.'/envelope.json')['properties']['event_type']['enum'];

    $onDisk = array_values(array_diff(
        array_map(
            static fn (string $path): string => basename($path, '.json'),
            glob($contractDirectory.'/*.json') ?: [],
        ),
        ['envelope'],
    ));

    sort($enum);
    sort($onDisk);

    expect($enum)->toBe($frozenTypes)
        ->and($onDisk)->toBe($frozenTypes)
        ->and($enum)->toContain(EventPublished::TYPE);
});

it('pins every type schema to its own event_type, to version one, and to a closed payload', function (string $type) use ($contractDirectory, $readSchema): void {
    $schema = $readSchema($contractDirectory.'/'.$type.'.json');

    expect($schema['properties']['event_type']['const'])->toBe($type)
        ->and($schema['properties']['version']['const'])->toBe(EventEnvelope::VERSION)
        ->and($schema['properties']['payload']['additionalProperties'])->toBeFalse()
        ->and($schema['allOf'][0]['$ref'])->toBe('envelope.json');
})->with($frozenTypes);

it('emits from EventPublished exactly the payload keys its own schema requires, and no others', function () use ($contractDirectory, $readSchema): void {
    $schema = $readSchema($contractDirectory.'/event.published.json')['properties']['payload'];

    $event = new EventPublished(7, [1, 2, 3]);

    expect(array_keys($event->payload()))->toBe($schema['required'])
        ->and(array_keys($event->payload()))->toBe(array_keys($schema['properties']))
        ->and($event->payload())->toBe(['event_id' => 7, 'seat_ids' => [1, 2, 3]])
        ->and($event->type())->toBe('event.published');
});
