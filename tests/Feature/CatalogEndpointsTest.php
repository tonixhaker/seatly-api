<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->ids = $this->seedCatalog();
});

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

it('lists only published events, so a draft and an archived one are absent', function (): void {
    $response = $this->getJson('/api/v1/events')->assertStatus(200);

    $ids = $response->json('data.*.id');

    expect($ids)->toBe($this->ids['published'])
        ->and($response->json('meta.total'))->toBe(3)
        ->and($ids)->not->toContain($this->ids['draft'])
        ->and($ids)->not->toContain($this->ids['archived'])
        ->and($response->json('data.0.title'))->toBe('Autumn Symphony')
        ->and($response->json('data.0.starts_at'))->toBe('2026-10-01T19:00:00Z')
        ->and($response->json('data.0.status'))->toBe('published')
        ->and($response->json('data.0.venue'))->toBe(['id' => $this->ids['arena'], 'name' => 'Riverside Arena'])
        ->and($response->json('data.2.title'))->toBe('Winter Jazz Night')
        ->and($response->json('data.2.venue'))->toBe(['id' => $this->ids['hall'], 'name' => 'Northgate Hall']);
});

it('orders the list by starts_at so the page is stable across identical requests', function (): void {
    $first = $this->getJson('/api/v1/events')->assertStatus(200)->json('data.*.id');
    $second = $this->getJson('/api/v1/events')->assertStatus(200)->json('data.*.id');

    expect($first)->toBe($second)->toBe($this->ids['published']);
});

it('honours per_page and page', function (): void {
    $response = $this->getJson('/api/v1/events?per_page=1&page=2')->assertStatus(200);

    expect($response->json('data.*.id'))->toBe([$this->ids['published'][1]])
        ->and($response->json('meta.current_page'))->toBe(2)
        ->and($response->json('meta.total'))->toBe(3);
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

it('narrows the list to events inside the inclusive requested range', function (): void {
    $response = $this->getJson('/api/v1/events?starts_from=2026-12-12&starts_until=2026-12-12')
        ->assertStatus(200);

    expect($response->json('data.*.id'))->toBe([$this->ids['published'][2]])
        ->and($response->json('meta.total'))->toBe(1);
});

it('issues a bounded number of queries whatever the page size', function (int $perPage): void {
    DB::connection()->flushQueryLog();
    DB::connection()->enableQueryLog();

    $this->getJson('/api/v1/events?per_page='.$perPage)->assertStatus(200);

    $log = DB::connection()->getQueryLog();
    DB::connection()->disableQueryLog();

    expect($log)->toHaveCount(3);
})->with([1, 3, 100]);

it('returns an unwrapped event detail with a full venue', function (): void {
    $id = $this->ids['published'][0];
    $response = $this->getJson('/api/v1/events/'.$id)->assertStatus(200);

    expect(array_keys((array) $response->json()))
        ->toBe(['id', 'title', 'description', 'starts_at', 'status', 'venue'])
        ->and($response->json('data'))->toBeNull()
        ->and($response->json('id'))->toBe($id)
        ->and($response->json('title'))->toBe('Autumn Symphony')
        ->and($response->json('description'))->toBe('An evening of late romantic repertoire.')
        ->and($response->json('starts_at'))->toBe('2026-10-01T19:00:00Z')
        ->and($response->json('status'))->toBe('published')
        ->and(array_keys((array) $response->json('venue')))->toBe(['id', 'name', 'address', 'city'])
        ->and($response->json('venue'))->toBe([
            'id' => $this->ids['arena'],
            'name' => 'Riverside Arena',
            'address' => '14 Quay Street',
            'city' => 'Rotterdam',
        ]);
});

it('renders a null description as an empty string, keeping the frozen contract non nullable', function (): void {
    $response = $this->getJson('/api/v1/events/'.$this->ids['empty'])->assertStatus(200);

    expect($response->json('description'))->toBe('')->toBeString();
});

it('hides a draft event behind a body byte identical to an unknown id', function (): void {
    $draft = $this->getJson('/api/v1/events/'.$this->ids['draft'])->assertStatus(404);
    $unknown = $this->getJson('/api/v1/events/'.($this->ids['published'][2] + 10_000))->assertStatus(404);

    expect($draft->getContent())->toBe($unknown->getContent())
        ->and($draft->json('error.code'))->toBe('NOT_FOUND')
        ->and($draft->getStatusCode())->not->toBe(403);
});

it('hides an archived event behind the same body as an unknown id', function (): void {
    $archived = $this->getJson('/api/v1/events/'.$this->ids['archived'])->assertStatus(404);
    $unknown = $this->getJson('/api/v1/events/'.($this->ids['published'][2] + 10_000))->assertStatus(404);

    expect($archived->getContent())->toBe($unknown->getContent())
        ->and($archived->json('error.code'))->toBe('NOT_FOUND');
});

it('returns NOT_FOUND rather than a server error for a non numeric id', function (): void {
    $response = $this->getJson('/api/v1/events/abc')
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'NOT_FOUND');

    expect($response->getStatusCode())->not->toBe(500);
});

it('returns the seat snapshot as a bare array of twelve seats', function (): void {
    $response = $this->getJson('/api/v1/events/'.$this->ids['published'][0].'/seats')->assertStatus(200);

    expect($response->json())->toBeArray()->toHaveCount(12)
        ->and(array_keys((array) $response->json()))->toBe(range(0, 11))
        ->and($response->json('data'))->toBeNull();
});

it('exposes exactly the nine seat fields including coordinates', function (): void {
    $response = $this->getJson('/api/v1/events/'.$this->ids['published'][0].'/seats')->assertStatus(200);

    foreach ((array) $response->json() as $seat) {
        expect(array_keys((array) $seat))
            ->toBe(['id', 'section', 'row', 'number', 'x', 'y', 'price_cents', 'currency', 'status'])
            ->and($seat['x'])->toBeInt()
            ->and($seat['y'])->toBeInt()
            ->and($seat['row'])->toBeInt()
            ->and($seat['number'])->toBeInt()
            ->and($seat['currency'])->toBe('EUR')
            ->and($seat['price_cents'])->toBe($seat['section'] === 'A' ? 5000 : 3500);
    }
});

it('orders seats by section, row and number regardless of insertion order', function (): void {
    DB::connection()->flushQueryLog();
    DB::connection()->enableQueryLog();

    $seats = (array) $this->getJson('/api/v1/events/'.$this->ids['published'][0].'/seats')->assertStatus(200)->json();

    $log = DB::connection()->getQueryLog();
    DB::connection()->disableQueryLog();

    $ordered = array_values(array_filter(
        array_column($log, 'query'),
        fn (string $sql): bool => str_contains($sql, 'from "event_seats"'),
    ));

    $actual = array_map(fn (array $seat): array => [$seat['section'], $seat['row'], $seat['number']], $seats);
    $expected = $actual;
    sort($expected);

    $ids = array_column($seats, 'id');
    $ascending = $ids;
    sort($ascending);

    expect($actual)->toBe($expected)
        ->and($actual[0])->toBe(['A', 1, 1])
        ->and($actual[11])->toBe(['B', 2, 3])
        ->and($ids)->not->toBe($ascending)
        ->and($ordered)->toHaveCount(1)
        ->and($ordered[0])->toContain('order by "section" asc, "row" asc, "number" asc');
});

it('returns the same seat order for the same request twice', function (): void {
    $path = '/api/v1/events/'.$this->ids['published'][0].'/seats';

    $first = array_column((array) $this->getJson($path)->assertStatus(200)->json(), 'id');
    $second = array_column((array) $this->getJson($path)->assertStatus(200)->json(), 'id');

    expect($first)->toBe($second)->toHaveCount(12);
});

it('only reports known seat statuses across more than one section', function (): void {
    $response = $this->getJson('/api/v1/events/'.$this->ids['published'][0].'/seats')->assertStatus(200);

    $seats = (array) $response->json();
    $statuses = array_values(array_unique(array_column($seats, 'status')));
    $sections = array_values(array_unique(array_column($seats, 'section')));

    sort($statuses);

    expect($statuses)->toBe(['free', 'sold'])
        ->and(count($sections))->toBeGreaterThanOrEqual(2)
        ->and(array_column(array_filter($seats, fn (array $seat): bool => $seat['status'] === 'sold'), 'id'))
        ->toEqualCanonicalizing($this->ids['sold']);
});

it('returns an empty array, not a 404, for a published event with no seats', function (): void {
    $response = $this->getJson('/api/v1/events/'.$this->ids['empty'].'/seats')->assertStatus(200);

    expect($response->json())->toBe([]);
});

it('returns an identical NOT_FOUND body for seats of a draft, an archived and an unknown event', function (): void {
    $unknown = $this->getJson('/api/v1/events/'.($this->ids['published'][2] + 10_000).'/seats')->assertStatus(404);
    $draft = $this->getJson('/api/v1/events/'.$this->ids['draft'].'/seats')->assertStatus(404);
    $archived = $this->getJson('/api/v1/events/'.$this->ids['archived'].'/seats')->assertStatus(404);

    expect($draft->getContent())->toBe($unknown->getContent())
        ->and($archived->getContent())->toBe($unknown->getContent())
        ->and($unknown->json('error.code'))->toBe('NOT_FOUND');
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
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.current_page', 1);
})->with([
    'starts_from=',
    'starts_until=',
    'page=',
    'per_page=',
    'starts_from=&starts_until=',
    'page=&per_page=',
]);
