<?php

declare(strict_types=1);

use App\Domain\User\Enums\UserRole;
use App\Domain\User\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

$as = function (int $userId, string $uri): TestResponse {
    $this->app['auth']->forgetGuards();

    return $this->actingAs(User::findOrFail($userId), 'sanctum')->getJson($uri);
};

it('lists every one of the caller events in every status and none of another organizer', function () use ($as): void {
    $ids = $this->seedCatalog();
    $stranger = User::factory()->create(['role' => UserRole::Organizer])->id;
    $this->insertEvent($stranger, $ids['arena'], 'Stranger Recital', null, '2026-10-15 19:00:00', 'published');

    $owner = $as->call($this, $ids['organizer'], '/api/v1/organizer/events')->assertOk();
    $other = $as->call($this, $stranger, '/api/v1/organizer/events')->assertOk();

    expect($owner->json('data.*.title'))->toEqualCanonicalizing(['Autumn Symphony', 'Winter Jazz Night', 'Spring Gala', 'Summer Retrospective', 'Seatless Preview'])
        ->and($owner->json('data.*.status'))->toEqualCanonicalizing(['published', 'published', 'published', 'draft', 'archived'])
        ->and($owner->json('meta.total'))->toBe(5)
        ->and($other->json('data.*.title'))->toBe(['Stranger Recital'])
        ->and($other->json('meta.total'))->toBe(1);
});

it('orders the caller events by starts_at, not by insertion order', function () use ($as): void {
    $ids = $this->seedCatalog();

    $titles = $as->call($this, $ids['organizer'], '/api/v1/organizer/events')->assertOk()->json('data.*.title');

    expect($titles)->toBe(['Summer Retrospective', 'Autumn Symphony', 'Seatless Preview', 'Winter Jazz Night', 'Spring Gala']);
});

it('paginates with the catalog page and per_page convention', function () use ($as): void {
    $ids = $this->seedCatalog();

    $first = $as->call($this, $ids['organizer'], '/api/v1/organizer/events?per_page=2')->assertOk();
    $last = $as->call($this, $ids['organizer'], '/api/v1/organizer/events?per_page=2&page=3')->assertOk();

    expect(array_keys((array) $first->json()))->toBe(['data', 'links', 'meta'])
        ->and($first->json('data'))->toHaveCount(2)
        ->and($first->json('meta.total'))->toBe(5)
        ->and($first->json('meta.last_page'))->toBe(3)
        ->and($first->json('meta.per_page'))->toBe(2)
        ->and($last->json('data.*.title'))->toBe(['Spring Gala'])
        ->and($last->json('meta.current_page'))->toBe(3);
});

it('rejects per_page above 100 with VALIDATION_FAILED', function () use ($as): void {
    $ids = $this->seedCatalog();

    $as->call($this, $ids['organizer'], '/api/v1/organizer/events?per_page=101')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED');
});

it('applies the starts_from filter to the caller events', function () use ($as): void {
    $ids = $this->seedCatalog();

    $titles = $as->call($this, $ids['organizer'], '/api/v1/organizer/events?starts_from=2026-11-01')->assertOk()->json('data.*.title');

    expect($titles)->toBe(['Seatless Preview', 'Winter Jazz Night', 'Spring Gala']);
});

it('returns each item with exactly the EventResource keys and a draft marked draft', function () use ($as): void {
    $ids = $this->seedCatalog();

    $items = collect($as->call($this, $ids['organizer'], '/api/v1/organizer/events')->assertOk()->json('data'));
    $draft = $items->firstWhere('id', $ids['draft']);

    expect($items)->each(fn ($item) => $item->toHaveKeys(['id', 'title', 'starts_at', 'status', 'venue']))
        ->and(array_keys((array) $draft))->toBe(['id', 'title', 'starts_at', 'status', 'venue'])
        ->and($draft['status'])->toBe('draft')
        ->and($draft['starts_at'])->toBe('2027-03-04T18:00:00Z')
        ->and($draft['venue'])->toBe(['id' => $ids['arena'], 'name' => 'Riverside Arena']);
});

it('shows the owner its draft with the full venue, as written to the database', function () use ($as): void {
    $ids = $this->seedCatalog();
    DB::table('events')->where('id', $ids['draft'])->update(['title' => 'Spring Gala, rehearsal cut']);

    $response = $as->call($this, $ids['organizer'], '/api/v1/organizer/events/'.$ids['draft'])->assertOk();

    expect(array_keys((array) $response->json()))->toBe(['id', 'title', 'description', 'starts_at', 'status', 'venue'])
        ->and($response->json('id'))->toBe($ids['draft'])
        ->and($response->json('title'))->toBe('Spring Gala, rehearsal cut')
        ->and($response->json('description'))->toBe('Not announced yet.')
        ->and($response->json('starts_at'))->toBe('2027-03-04T18:00:00Z')
        ->and($response->json('status'))->toBe('draft')
        ->and($response->json('venue'))->toBe([
            'id' => $ids['arena'],
            'name' => 'Riverside Arena',
            'address' => '14 Quay Street',
            'city' => 'Rotterdam',
        ]);
});

it('gives a stranger organizer, an unknown id and an oversized id the same 404 body', function () use ($as): void {
    $ids = $this->seedCatalog();
    $stranger = User::factory()->create(['role' => UserRole::Organizer])->id;

    $as->call($this, $ids['organizer'], '/api/v1/organizer/events/'.$ids['draft'])->assertOk();

    $bodies = [];

    foreach ([[$stranger, $ids['draft']], [$ids['organizer'], 999999999], [$ids['organizer'], '12345678901234567890']] as [$actor, $id]) {
        $bodies[] = $as->call($this, $actor, '/api/v1/organizer/events/'.$id)->assertStatus(404)->getContent();
    }

    expect($bodies[0])->toBe('{"error":{"code":"NOT_FOUND","message":"The requested resource was not found."}}')
        ->and($bodies[1])->toBe($bodies[0])
        ->and($bodies[2])->toBe($bodies[0]);
});

it('gives a buyer 403 FORBIDDEN on every organizer read, never 404', function (string $uri): void {
    $ids = $this->seedCatalog();
    $buyer = User::factory()->create(['role' => UserRole::Buyer]);

    $this->actingAs($buyer, 'sanctum')
        ->getJson(str_replace('{draft}', (string) $ids['draft'], $uri))
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'FORBIDDEN');
})->with([
    '/api/v1/organizer/events',
    '/api/v1/organizer/events/{draft}',
    '/api/v1/organizer/venues',
]);

it('lists venues as a bare array in name order with only id, name, address and city', function () use ($as): void {
    $ids = $this->seedCatalog();

    $response = $as->call($this, $ids['organizer'], '/api/v1/organizer/venues')->assertOk();
    $body = $response->json();

    expect($body)->toBeArray()
        ->and(array_is_list($body))->toBeTrue()
        ->and($body)->toBe([
            ['id' => $ids['hall'], 'name' => 'Northgate Hall', 'address' => '3 Market Square', 'city' => 'Utrecht'],
            ['id' => $ids['arena'], 'name' => 'Riverside Arena', 'address' => '14 Quay Street', 'city' => 'Rotterdam'],
        ])
        ->and($response->getContent())->not->toContain('seat_map_template')
        ->and($response->getContent())->not->toContain('sections');
});

it('serves the demo organizer its own events including the draft, and hides the draft from the other organizer', function () use ($as): void {
    $this->seed(DatabaseSeeder::class);

    $owner = User::query()->where('email', DatabaseSeeder::ORGANIZER_EMAIL)->firstOrFail()->id;
    $other = User::query()->where('email', DatabaseSeeder::SECOND_ORGANIZER_EMAIL)->firstOrFail()->id;
    $draft = (int) DB::table('events')->where('title', 'Spring Gala Preview')->value('id');

    $titles = $as->call($this, $owner, '/api/v1/organizer/events')->assertOk()->json('data.*.title');

    expect($titles)->toEqualCanonicalizing(['Autumn Symphony', 'Spring Gala Preview'])
        ->not->toContain('Winter Jazz Night');

    $as->call($this, $owner, '/api/v1/organizer/events/'.$draft)->assertOk()->assertJsonPath('status', 'draft');
    $as->call($this, $other, '/api/v1/organizer/events/'.$draft)->assertStatus(404);

    $venues = $as->call($this, $owner, '/api/v1/organizer/venues')->assertOk();

    expect($venues->json('*.name'))->toBe(['Northgate Hall', 'Riverside Arena'])
        ->and($venues->getContent())->not->toContain('seat_map_template')
        ->and($venues->getContent())->not->toContain('price_cents');
});
