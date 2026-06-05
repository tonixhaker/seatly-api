<?php

declare(strict_types=1);

it('returns a paginated envelope with exactly data, links and meta', function (): void {
    $response = $this->getJson('/api/v1/events')->assertStatus(200);

    expect(array_keys((array) $response->json()))->toBe(['data', 'links', 'meta']);
});

it('exposes exactly the list fields on each event and only id and name on its venue', function (): void {
    $response = $this->getJson('/api/v1/events')->assertStatus(200);

    foreach ((array) $response->json('data') as $event) {
        expect(array_keys((array) $event))->toBe(['id', 'title', 'starts_at', 'status', 'venue'])
            ->and(array_keys((array) $event['venue']))->toBe(['id', 'name']);
    }
});

it('lists only published events', function (): void {
    $response = $this->getJson('/api/v1/events')->assertStatus(200);

    expect($response->json('data.*.id'))->toBe([1, 2])
        ->and($response->json('meta.total'))->toBe(2)
        ->and($response->json('data.0.title'))->toBe('Autumn Symphony')
        ->and($response->json('data.0.starts_at'))->toBe('2026-10-01T19:00:00Z')
        ->and($response->json('data.0.status'))->toBe('published')
        ->and($response->json('data.0.venue'))->toBe(['id' => 1, 'name' => 'Riverside Arena'])
        ->and($response->json('data.1.title'))->toBe('Winter Jazz Night')
        ->and($response->json('data.1.starts_at'))->toBe('2026-12-12T20:30:00Z')
        ->and($response->json('data.1.venue'))->toBe(['id' => 2, 'name' => 'Northgate Hall']);
});

it('honours per_page and page', function (): void {
    $response = $this->getJson('/api/v1/events?per_page=1&page=2')->assertStatus(200);

    expect($response->json('data.*.id'))->toBe([2])
        ->and($response->json('meta.current_page'))->toBe(2)
        ->and($response->json('meta.total'))->toBe(2);
});

it('rejects a malformed starts_from and names the field in details', function (): void {
    $this->getJson('/api/v1/events?starts_from=notadate')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED')
        ->assertJsonStructure(['error' => ['details' => ['starts_from']]]);
});

it('rejects a starts_until earlier than starts_from and names the field in details', function (): void {
    $this->getJson('/api/v1/events?starts_from=2026-11-01&starts_until=2026-10-01')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED')
        ->assertJsonStructure(['error' => ['details' => ['starts_until']]]);
});

it('narrows the list to events inside the requested range', function (): void {
    $response = $this->getJson('/api/v1/events?starts_from=2026-11-01&starts_until=2026-12-31')
        ->assertStatus(200);

    expect($response->json('data.*.id'))->toBe([2])
        ->and($response->json('meta.total'))->toBe(1);
});

it('returns an unwrapped event detail with a full venue', function (): void {
    $response = $this->getJson('/api/v1/events/1')->assertStatus(200);

    expect(array_keys((array) $response->json()))
        ->toBe(['id', 'title', 'description', 'starts_at', 'status', 'venue'])
        ->and($response->json('data'))->toBeNull()
        ->and($response->json('id'))->toBe(1)
        ->and($response->json('title'))->toBe('Autumn Symphony')
        ->and($response->json('description'))->toBeString()->not->toBeEmpty()
        ->and($response->json('starts_at'))->toBe('2026-10-01T19:00:00Z')
        ->and($response->json('status'))->toBe('published')
        ->and(array_keys((array) $response->json('venue')))->toBe(['id', 'name', 'address', 'city'])
        ->and($response->json('venue'))->toBe([
            'id' => 1,
            'name' => 'Riverside Arena',
            'address' => '14 Quay Street',
            'city' => 'Rotterdam',
        ]);
});

it('hides a draft event behind NOT_FOUND rather than FORBIDDEN', function (): void {
    $response = $this->getJson('/api/v1/events/3')
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'NOT_FOUND');

    expect($response->getStatusCode())->not->toBe(403);
});

it('returns NOT_FOUND for an archived event and for an unknown id', function (): void {
    $this->getJson('/api/v1/events/4')
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'NOT_FOUND');

    $this->getJson('/api/v1/events/999')
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'NOT_FOUND');
});

it('returns NOT_FOUND rather than a server error for a non numeric id', function (): void {
    $response = $this->getJson('/api/v1/events/abc')
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'NOT_FOUND');

    expect($response->getStatusCode())->not->toBe(500);
});

it('returns the seat snapshot as a bare array of twelve seats', function (): void {
    $response = $this->getJson('/api/v1/events/1/seats')->assertStatus(200);

    expect($response->json())->toBeArray()->toHaveCount(12)
        ->and(array_keys((array) $response->json()))->toBe(range(0, 11))
        ->and($response->json('data'))->toBeNull();
});

it('exposes exactly the nine seat fields including coordinates', function (): void {
    $response = $this->getJson('/api/v1/events/1/seats')->assertStatus(200);

    foreach ((array) $response->json() as $seat) {
        expect(array_keys((array) $seat))
            ->toBe(['id', 'section', 'row', 'number', 'x', 'y', 'price_cents', 'currency', 'status'])
            ->and($seat['x'])->toBeInt()
            ->and($seat['y'])->toBeInt()
            ->and($seat['currency'])->toBe('EUR')
            ->and($seat['price_cents'])->toBe($seat['section'] === 'A' ? 5000 : 3500);
    }
});

it('only reports known seat statuses across more than one section', function (): void {
    $response = $this->getJson('/api/v1/events/1/seats')->assertStatus(200);

    $seats = (array) $response->json();
    $statuses = array_values(array_unique(array_column($seats, 'status')));
    $sections = array_values(array_unique(array_column($seats, 'section')));

    sort($statuses);

    expect($statuses)->toBe(['free', 'sold'])
        ->and(count($sections))->toBeGreaterThanOrEqual(2)
        ->and(array_column(array_filter($seats, fn (array $seat): bool => $seat['status'] === 'sold'), 'id'))
        ->toEqualCanonicalizing([3, 7, 11]);
});

it('returns NOT_FOUND for seats of a draft event and of an unknown event', function (): void {
    $this->getJson('/api/v1/events/3/seats')
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'NOT_FOUND');

    $this->getJson('/api/v1/events/999/seats')
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'NOT_FOUND');
});

it('returns 404 rather than 500 for an id larger than PHP_INT_MAX', function (string $path): void {
    $response = $this->getJson($path);

    expect($response->getStatusCode())->toBe(404)->not->toBe(500);
    $response->assertJsonPath('error.code', 'NOT_FOUND');
})->with([
    '/api/v1/events/9223372036854775808',
    '/api/v1/events/9999999999999999999',
    '/api/v1/events/1000000000000000000000',
    '/api/v1/events/9999999999999999999/seats',
]);

it('ignores empty query filters instead of rejecting them', function (string $query): void {
    $this->getJson('/api/v1/events?'.$query)
        ->assertStatus(200)
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('meta.current_page', 1);
})->with([
    'starts_from=',
    'starts_until=',
    'page=',
    'per_page=',
    'starts_from=&starts_until=',
    'page=&per_page=',
]);
