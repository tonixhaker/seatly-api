<?php

declare(strict_types=1);

use App\Domain\User\Enums\UserRole;
use App\Domain\User\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

$fixtureOrganizer = function (): User {
    return User::factory()->make(['id' => 10, 'role' => 'organizer']);
};

$eventPayload = function (int $venueId, array $overrides = []): array {
    return array_merge([
        'venue_id' => $venueId,
        'title' => 'Late Night Strings',
        'description' => 'A short programme of chamber works.',
        'starts_at' => '2027-05-01T19:00:00Z',
    ], $overrides);
};

beforeEach(function (): void {
    $this->ids = $this->seedCatalog();
    $this->owner = User::findOrFail($this->ids['organizer']);
    $this->stranger = User::factory()->create(['role' => UserRole::Organizer]);
    $this->buyer = User::factory()->create(['role' => UserRole::Buyer]);
});

dataset('organizerRoutes', [
    'POST /organizer/events' => ['post', '/api/v1/organizer/events'],
    'PUT /organizer/events/{id}' => ['put', '/api/v1/organizer/events/3'],
    'POST /organizer/events/{id}/publish' => ['post', '/api/v1/organizer/events/3/publish'],
    'GET /organizer/events/{id}/stats' => ['get', '/api/v1/organizer/events/1/stats'],
    'POST /organizer/check-in' => ['post', '/api/v1/organizer/check-in'],
]);

it('factory make keeps the id and casts the role to the UserRole enum', function () use ($fixtureOrganizer): void {
    expect($fixtureOrganizer()->getAttribute('id'))->toBe(10)
        ->and($fixtureOrganizer()->getAttribute('role'))->toBe(UserRole::Organizer);
});

it('401 without a token on every organizer route, with no details key', function (string $method, string $uri) use ($eventPayload): void {
    $response = $this->json($method, $uri, $eventPayload($this->ids['arena'], ['qr_code' => 'A1B2C3D4E5F6']))
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'UNAUTHENTICATED');

    expect(array_keys((array) $response->json('error')))->toBe(['code', 'message']);
})->with('organizerRoutes');

it('403 on every organizer route for a buyer, a role-less user and a wrong-case role', function (string $method, string $uri) use ($eventPayload): void {
    foreach ([['role' => 'buyer'], ['role' => null]] as $attributes) {
        $response = $this->actingAs(User::factory()->make($attributes), 'sanctum')
            ->json($method, $uri, $eventPayload($this->ids['arena'], ['qr_code' => 'A1B2C3D4E5F6']))
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');

        expect(array_keys((array) $response->json('error')))->toBe(['code', 'message']);
    }
})->with('organizerRoutes');

it('gives a buyer 403 FORBIDDEN on create and on update, never 404', function () use ($eventPayload): void {
    $create = $this->actingAs($this->buyer, 'sanctum')
        ->postJson('/api/v1/organizer/events', $eventPayload($this->ids['arena']));

    $update = $this->actingAs($this->buyer, 'sanctum')
        ->putJson('/api/v1/organizer/events/'.$this->ids['draft'], $eventPayload($this->ids['arena']));

    expect($create->getStatusCode())->toBe(403)
        ->and($create->json('error.code'))->toBe('FORBIDDEN')
        ->and($update->getStatusCode())->toBe(403)
        ->and($update->json('error.code'))->toBe('FORBIDDEN');
});

it('gives another organizer 404 NOT_FOUND on update, never 403', function () use ($eventPayload): void {
    $response = $this->actingAs($this->stranger, 'sanctum')
        ->putJson('/api/v1/organizer/events/'.$this->ids['draft'], $eventPayload($this->ids['arena']));

    expect($response->getStatusCode())->toBe(404)
        ->and($response->json('error.code'))->toBe('NOT_FOUND');
});

it('makes another organizer draft byte-identical to an id that never existed', function () use ($eventPayload): void {
    $foreign = $this->actingAs($this->stranger, 'sanctum')
        ->putJson('/api/v1/organizer/events/'.$this->ids['draft'], $eventPayload($this->ids['arena']))
        ->assertStatus(404);

    $unknown = $this->actingAs($this->stranger, 'sanctum')
        ->putJson('/api/v1/organizer/events/999999', $eventPayload($this->ids['arena']))
        ->assertStatus(404);

    $unroutable = $this->actingAs($this->stranger, 'sanctum')
        ->putJson('/api/v1/organizer/events/12345678901234567890', $eventPayload($this->ids['arena']))
        ->assertStatus(404);

    expect($foreign->getContent())->toBe('{"error":{"code":"NOT_FOUND","message":"The requested resource was not found."}}')
        ->and($unknown->getContent())->toBe($foreign->getContent())
        ->and($unroutable->getContent())->toBe($foreign->getContent());
});

it('keeps the wrong role and the wrong owner distinguishable, which is what stops a draft leaking', function () use ($eventPayload): void {
    $buyer = $this->actingAs($this->buyer, 'sanctum')
        ->putJson('/api/v1/organizer/events/'.$this->ids['draft'], $eventPayload($this->ids['arena']));

    $stranger = $this->actingAs($this->stranger, 'sanctum')
        ->putJson('/api/v1/organizer/events/'.$this->ids['draft'], $eventPayload($this->ids['arena']));

    expect($buyer->getStatusCode())->toBe(403)
        ->and($stranger->getStatusCode())->toBe(404)
        ->and($buyer->getStatusCode())->not->toBe($stranger->getStatusCode())
        ->and($buyer->getContent())->not->toBe($stranger->getContent());
});

it('creates a draft owned by the caller and returns exactly the detail shape', function () use ($eventPayload): void {
    $response = $this->actingAs($this->owner, 'sanctum')
        ->postJson('/api/v1/organizer/events', $eventPayload($this->ids['arena']))
        ->assertStatus(201);

    expect(array_keys((array) $response->json()))->toBe(['id', 'title', 'description', 'starts_at', 'status', 'venue'])
        ->and($response->json('status'))->toBe('draft')
        ->and($response->json('title'))->toBe('Late Night Strings')
        ->and($response->json('description'))->toBe('A short programme of chamber works.')
        ->and($response->json('starts_at'))->toBe('2027-05-01T19:00:00Z')
        ->and($response->json('venue'))->toBe([
            'id' => $this->ids['arena'],
            'name' => 'Riverside Arena',
            'address' => '14 Quay Street',
            'city' => 'Rotterdam',
        ]);
});

it('writes the created event to the database, owned by the caller and marked draft', function () use ($eventPayload): void {
    $id = $this->actingAs($this->owner, 'sanctum')
        ->postJson('/api/v1/organizer/events', $eventPayload($this->ids['arena']))
        ->assertStatus(201)
        ->json('id');

    $row = DB::table('events')->where('id', $id)->first();

    expect($row)->not->toBeNull()
        ->and((int) $row->organizer_id)->toBe($this->owner->id)
        ->and($row->status)->toBe('draft')
        ->and($row->title)->toBe('Late Night Strings')
        ->and($row->description)->toBe('A short programme of chamber works.')
        ->and(CarbonImmutable::parse((string) $row->starts_at)->utc()->toIso8601ZuluString())->toBe('2027-05-01T19:00:00Z')
        ->and((int) $row->venue_id)->toBe($this->ids['arena']);
});

it('ignores a client supplied status on create, in the response and in the row', function () use ($eventPayload): void {
    $response = $this->actingAs($this->owner, 'sanctum')
        ->postJson('/api/v1/organizer/events', $eventPayload($this->ids['arena'], ['status' => 'published']))
        ->assertStatus(201)
        ->assertJsonPath('status', 'draft');

    expect(DB::table('events')->where('id', $response->json('id'))->value('status'))->toBe('draft');
});

it('422 for a venue_id that matches no venue, on create and on update', function () use ($eventPayload): void {
    $absent = DB::table('venues')->max('id') + 1000;

    $create = $this->actingAs($this->owner, 'sanctum')
        ->postJson('/api/v1/organizer/events', $eventPayload($absent));

    $update = $this->actingAs($this->owner, 'sanctum')
        ->putJson('/api/v1/organizer/events/'.$this->ids['draft'], $eventPayload($absent));

    expect($create->getStatusCode())->toBe(422)
        ->and($create->json('error.code'))->toBe('VALIDATION_FAILED')
        ->and($create->json('error.details.venue_id'))->not->toBeNull()
        ->and($update->getStatusCode())->toBe(422)
        ->and($update->json('error.code'))->toBe('VALIDATION_FAILED')
        ->and($update->json('error.details.venue_id'))->not->toBeNull();
});

it('converts a starts_at offset to UTC instead of dropping it', function () use ($eventPayload): void {
    $response = $this->actingAs($this->owner, 'sanctum')
        ->postJson('/api/v1/organizer/events', $eventPayload($this->ids['arena'], ['starts_at' => '2027-05-01T19:00:00+02:00']))
        ->assertStatus(201);

    expect($response->json('starts_at'))->toBe('2027-05-01T17:00:00Z')
        ->and(CarbonImmutable::parse((string) DB::table('events')->where('id', $response->json('id'))->value('starts_at'))->utc()->toIso8601ZuluString())
        ->toBe('2027-05-01T17:00:00Z');
});

it('keeps a description of "0", which a falsy check would turn into null', function () use ($eventPayload): void {
    $response = $this->actingAs($this->owner, 'sanctum')
        ->postJson('/api/v1/organizer/events', $eventPayload($this->ids['arena'], ['description' => '0']))
        ->assertStatus(201);

    expect($response->json('description'))->toBe('0')
        ->and(DB::table('events')->where('id', $response->json('id'))->value('description'))->toBe('0');
});

it('stores no description at all when none is sent, and reads it back as an empty string', function () use ($eventPayload): void {
    $payload = $eventPayload($this->ids['arena']);
    unset($payload['description']);

    $response = $this->actingAs($this->owner, 'sanctum')
        ->postJson('/api/v1/organizer/events', $payload)
        ->assertStatus(201);

    expect($response->json('description'))->toBe('')
        ->and(DB::table('events')->where('id', $response->json('id'))->value('description'))->toBeNull();
});

it('updates a draft in place without moving it out of draft', function () use ($eventPayload): void {
    $response = $this->actingAs($this->owner, 'sanctum')
        ->putJson('/api/v1/organizer/events/'.$this->ids['draft'], $eventPayload($this->ids['arena'], ['title' => 'Renamed Gala']))
        ->assertStatus(200)
        ->assertJsonPath('status', 'draft')
        ->assertJsonPath('id', $this->ids['draft'])
        ->assertJsonPath('title', 'Renamed Gala');

    $row = DB::table('events')->where('id', $this->ids['draft'])->first();

    expect($row->title)->toBe('Renamed Gala')
        ->and($row->status)->toBe('draft');
});

it('reports the new venue after an update moves the event to a different one', function () use ($eventPayload): void {
    $response = $this->actingAs($this->owner, 'sanctum')
        ->putJson('/api/v1/organizer/events/'.$this->ids['draft'], $eventPayload($this->ids['hall']))
        ->assertStatus(200);

    expect($response->json('venue'))->toBe([
        'id' => $this->ids['hall'],
        'name' => 'Northgate Hall',
        'address' => '3 Market Square',
        'city' => 'Utrecht',
    ])->and((int) DB::table('events')->where('id', $this->ids['draft'])->value('venue_id'))->toBe($this->ids['hall']);
});

it('409 INVALID_STATE_TRANSITION when the owner edits an event that is not a draft', function (string $key, string $status) use ($eventPayload): void {
    $id = $key === 'published' ? $this->ids['published'][0] : $this->ids['archived'];

    $this->actingAs($this->owner, 'sanctum')
        ->putJson('/api/v1/organizer/events/'.$id, $eventPayload($this->ids['arena']))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'INVALID_STATE_TRANSITION')
        ->assertJsonPath('error.details.status', $status);
})->with([
    'published' => ['published', 'published'],
    'archived' => ['archived', 'archived'],
]);

it('404 not 409 when the non-draft event belongs to another organizer', function () use ($eventPayload): void {
    $response = $this->actingAs($this->stranger, 'sanctum')
        ->putJson('/api/v1/organizer/events/'.$this->ids['published'][0], $eventPayload($this->ids['arena']))
        ->assertStatus(404);

    expect($response->json('error.code'))->toBe('NOT_FOUND');
});

it('hides a freshly created draft from the public catalog until its status says otherwise', function () use ($eventPayload): void {
    $created = $this->actingAs($this->owner, 'sanctum')
        ->postJson('/api/v1/organizer/events', $eventPayload($this->ids['arena']))
        ->assertStatus(201);

    $this->getJson('/api/v1/events/'.$created->json('id'))->assertStatus(404);

    DB::table('events')->where('id', $created->json('id'))->update(['status' => 'published']);

    $this->getJson('/api/v1/events/'.$created->json('id'))->assertStatus(200);
});

it('serves the organizer created event through the public catalog unchanged', function () use ($eventPayload): void {
    $created = $this->actingAs($this->owner, 'sanctum')
        ->postJson('/api/v1/organizer/events', $eventPayload($this->ids['arena']))
        ->assertStatus(201);

    DB::table('events')->where('id', $created->json('id'))->update(['status' => 'published']);

    $public = $this->getJson('/api/v1/events/'.$created->json('id'))->assertStatus(200);

    $expected = (array) $created->json();
    $expected['status'] = 'published';

    expect($public->json())->toBe($expected);
});

it('422 naming the field the create rules rejected', function (array $overrides, string $field) use ($eventPayload): void {
    $this->actingAs($this->owner, 'sanctum')
        ->postJson('/api/v1/organizer/events', $eventPayload($this->ids['arena'], $overrides))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED')
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with([
    'starts_at in the past' => [['starts_at' => '2020-01-01T19:00:00Z'], 'starts_at'],
    'starts_at in prose' => [['starts_at' => 'next tuesday'], 'starts_at'],
    'empty title' => [['title' => ''], 'title'],
    'title over 255 characters' => [['title' => str_repeat('t', 256)], 'title'],
    'description over 2000 characters' => [['description' => str_repeat('d', 2001)], 'description'],
    'boolean venue_id' => [['venue_id' => true], 'venue_id'],
    'zero venue_id' => [['venue_id' => 0], 'venue_id'],
    'non numeric venue_id' => [['venue_id' => 'abc'], 'venue_id'],
]);

it('422 naming the field the update rules rejected', function (array $overrides, string $field) use ($eventPayload): void {
    $this->actingAs($this->owner, 'sanctum')
        ->putJson('/api/v1/organizer/events/'.$this->ids['draft'], $eventPayload($this->ids['arena'], $overrides))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED')
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with([
    'starts_at in the past' => [['starts_at' => '2020-01-01T19:00:00Z'], 'starts_at'],
    'starts_at in prose' => [['starts_at' => 'next tuesday'], 'starts_at'],
    'empty title' => [['title' => ''], 'title'],
    'title over 255 characters' => [['title' => str_repeat('t', 256)], 'title'],
    'description over 2000 characters' => [['description' => str_repeat('d', 2001)], 'description'],
    'boolean venue_id' => [['venue_id' => true], 'venue_id'],
    'zero venue_id' => [['venue_id' => 0], 'venue_id'],
    'non numeric venue_id' => [['venue_id' => 'abc'], 'venue_id'],
]);

it('422 naming a required field missing from create and from update', function (string $field) use ($eventPayload): void {
    $payload = $eventPayload($this->ids['arena']);
    unset($payload[$field]);

    $this->actingAs($this->owner, 'sanctum')
        ->postJson('/api/v1/organizer/events', $payload)
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['details' => [$field]]]);

    $this->actingAs($this->owner, 'sanctum')
        ->putJson('/api/v1/organizer/events/'.$this->ids['draft'], $payload)
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with(['venue_id', 'title', 'starts_at']);

it('accepts the boundary values on create and on update', function (array $overrides) use ($eventPayload): void {
    $this->actingAs($this->owner, 'sanctum')
        ->postJson('/api/v1/organizer/events', $eventPayload($this->ids['arena'], $overrides))
        ->assertStatus(201);

    $this->actingAs($this->owner, 'sanctum')
        ->putJson('/api/v1/organizer/events/'.$this->ids['draft'], $eventPayload($this->ids['arena'], $overrides))
        ->assertStatus(200);
})->with([
    'zulu starts_at' => [['starts_at' => '2027-05-01T19:00:00Z']],
    'offset starts_at' => [['starts_at' => '2027-05-01T19:00:00+02:00']],
    'title of exactly 255 characters' => [['title' => str_repeat('t', 255)]],
    'description of exactly 2000 characters' => [['description' => str_repeat('d', 2000)]],
]);

it('accepts a payload with no description at all on both routes', function () use ($eventPayload): void {
    $payload = $eventPayload($this->ids['arena']);
    unset($payload['description']);

    $this->actingAs($this->owner, 'sanctum')
        ->postJson('/api/v1/organizer/events', $payload)
        ->assertStatus(201);

    $this->actingAs($this->owner, 'sanctum')
        ->putJson('/api/v1/organizer/events/'.$this->ids['draft'], $payload)
        ->assertStatus(200);
});

it('rejects a starts_at whose UTC offset cannot exist', function (string $startsAt, int $status) use ($eventPayload): void {
    $this->actingAs($this->owner, 'sanctum')
        ->postJson('/api/v1/organizer/events', $eventPayload($this->ids['arena'], ['starts_at' => $startsAt]))
        ->assertStatus($status);
})->with([
    'max legal offset' => ['2027-05-01T19:00:00+14:00', 201],
    'max legal negative offset' => ['2027-05-01T19:00:00-14:00', 201],
    'offset beyond the dateline' => ['2027-05-01T19:00:00+25:00', 422],
    'offset minutes out of range' => ['2027-05-01T19:00:00+14:60', 422],
    'lowercase z' => ['2027-05-01T19:00:00z', 422],
    'fractional seconds' => ['2027-05-01T19:00:00.500Z', 422],
    'no offset at all' => ['2027-05-01T19:00:00', 422],
]);

it('rejects control characters in title and a nul byte in description', function (array $overrides, int $status) use ($eventPayload): void {
    $this->actingAs($this->owner, 'sanctum')
        ->postJson('/api/v1/organizer/events', $eventPayload($this->ids['arena'], $overrides))
        ->assertStatus($status);
})->with([
    'nul in title' => [['title' => "a\u{0000}b"], 422],
    'control char in title' => [['title' => "a\u{0001}b"], 422],
    'delete char in title' => [['title' => "a\u{007F}b"], 422],
    'newline in title' => [['title' => "a\nb"], 422],
    'unicode title is fine' => [['title' => 'Ünïcödé — Strings 🎻'], 201],
    'nul in description' => [['description' => "bad\u{0000}nul"], 422],
    'newline in description is fine' => [['description' => "line one\nline two"], 201],
]);

it('applies the same starts_at and title rules on update', function () use ($eventPayload): void {
    $this->actingAs($this->owner, 'sanctum')
        ->putJson('/api/v1/organizer/events/'.$this->ids['draft'], $eventPayload($this->ids['arena'], ['starts_at' => '2027-05-01T19:00:00+25:00']))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED');

    $this->actingAs($this->owner, 'sanctum')
        ->putJson('/api/v1/organizer/events/'.$this->ids['draft'], $eventPayload($this->ids['arena'], ['title' => "a\u{0000}b"]))
        ->assertStatus(422);
});

it('hostile input never 500s', function (string $route, array $overrides) use ($eventPayload): void {
    $payload = $route === 'check-in' ? $overrides : $eventPayload($this->ids['arena'], $overrides);

    $status = match ($route) {
        'create' => $this->actingAs($this->owner, 'sanctum')->postJson('/api/v1/organizer/events', $payload)->getStatusCode(),
        'update' => $this->actingAs($this->owner, 'sanctum')->putJson('/api/v1/organizer/events/'.$this->ids['draft'], $payload)->getStatusCode(),
        default => $this->actingAs($this->owner, 'sanctum')->postJson('/api/v1/organizer/check-in', $payload)->getStatusCode(),
    };

    expect($status)->toBe(422)->not->toBe(500);
})->with([
    'array title on create' => ['create', ['title' => ['a']]],
    'array venue_id on create' => ['create', ['venue_id' => [1]]],
    'array starts_at on create' => ['create', ['starts_at' => ['2027-05-01T19:00:00Z']]],
    'array title on update' => ['update', ['title' => ['a']]],
    'array venue_id on update' => ['update', ['venue_id' => [1]]],
    'array starts_at on update' => ['update', ['starts_at' => ['2027-05-01T19:00:00Z']]],
    'array qr_code on check-in' => ['check-in', ['qr_code' => ['A1B2C3D4E5F6']]],
]);

it('405 METHOD_NOT_ALLOWED on a wrong verb against the update route', function () use ($eventPayload): void {
    $this->actingAs($this->owner, 'sanctum')
        ->patchJson('/api/v1/organizer/events/'.$this->ids['draft'], $eventPayload($this->ids['arena']))
        ->assertStatus(405)
        ->assertJsonPath('error.code', 'METHOD_NOT_ALLOWED');
});

it('another organizer event is 404 never 403 on the fixture routes', function (string $method, string $uri) use ($fixtureOrganizer): void {
    $response = $this->actingAs($fixtureOrganizer(), 'sanctum')
        ->json($method, $uri)
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'NOT_FOUND');

    expect($response->getStatusCode())->not->toBe(403);
})->with([
    'publish' => ['post', '/api/v1/organizer/events/2/publish'],
    'stats' => ['get', '/api/v1/organizer/events/2/stats'],
]);

it('a ticket for another organizer event is 404 never 403', function () use ($fixtureOrganizer): void {
    $response = $this->actingAs($fixtureOrganizer(), 'sanctum')
        ->postJson('/api/v1/organizer/check-in', ['qr_code' => 'N3P4Q5R6S7T8'])
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'NOT_FOUND');

    expect($response->getStatusCode())->not->toBe(403);
});

it('a foreign event, an unknown id and an oversized id are indistinguishable on stats', function () use ($fixtureOrganizer): void {
    $bodies = [];

    foreach (['2', '999', '12345678901234567890'] as $id) {
        $response = $this->actingAs($fixtureOrganizer(), 'sanctum')
            ->getJson('/api/v1/organizer/events/'.$id.'/stats')
            ->assertStatus(404);

        $bodies[] = $response->getContent();
    }

    expect($bodies[0])->toBe('{"error":{"code":"NOT_FOUND","message":"The requested resource was not found."}}')
        ->and($bodies[1])->toBe($bodies[0])
        ->and($bodies[2])->toBe($bodies[0]);
});

it('422 for a qr code of the wrong length, the wrong type or none at all', function (array $payload) use ($fixtureOrganizer): void {
    $this->actingAs($fixtureOrganizer(), 'sanctum')
        ->postJson('/api/v1/organizer/check-in', $payload)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED')
        ->assertJsonStructure(['error' => ['details' => ['qr_code']]]);
})->with([
    'eleven characters' => [['qr_code' => 'A1B2C3D4E5F']],
    'thirteen characters' => [['qr_code' => 'A1B2C3D4E5F6G']],
    'an integer' => [['qr_code' => 123456789012]],
    'missing' => [[]],
]);

it('404 for a well formed qr code that matches no ticket', function () use ($fixtureOrganizer): void {
    $this->actingAs($fixtureOrganizer(), 'sanctum')
        ->postJson('/api/v1/organizer/check-in', ['qr_code' => 'Z9Y8X7W6V5U4'])
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'NOT_FOUND');
});

it('publishes a draft', function () use ($fixtureOrganizer): void {
    $this->actingAs($fixtureOrganizer(), 'sanctum')
        ->postJson('/api/v1/organizer/events/3/publish')
        ->assertStatus(200)
        ->assertJsonPath('id', 3)
        ->assertJsonPath('status', 'published')
        ->assertJsonPath('title', 'Spring Gala')
        ->assertJsonPath('starts_at', '2027-03-04T18:00:00Z');
});

it('409 INVALID_STATE_TRANSITION when publishing an event that is not a draft', function (int $id, string $status) use ($fixtureOrganizer): void {
    $this->actingAs($fixtureOrganizer(), 'sanctum')
        ->postJson('/api/v1/organizer/events/'.$id.'/publish')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'INVALID_STATE_TRANSITION')
        ->assertJsonPath('error.details.status', $status);
})->with([
    'published' => [1, 'published'],
    'archived' => [4, 'archived'],
]);

it('reports the sales dashboard for an event that has seats', function (int $id) use ($fixtureOrganizer): void {
    $response = $this->actingAs($fixtureOrganizer(), 'sanctum')
        ->getJson('/api/v1/organizer/events/'.$id.'/stats')
        ->assertStatus(200);

    expect($response->json())->toBe([
        'event_id' => $id,
        'seats_total' => 12,
        'seats_sold' => 3,
        'seats_free' => 9,
        'revenue_cents' => 12000,
        'currency' => 'EUR',
    ]);
})->with([1, 4]);

it('reports every counter as zero for a draft that has no seats yet', function () use ($fixtureOrganizer): void {
    $response = $this->actingAs($fixtureOrganizer(), 'sanctum')
        ->getJson('/api/v1/organizer/events/3/stats')
        ->assertStatus(200);

    expect($response->json())->toBe([
        'event_id' => 3,
        'seats_total' => 0,
        'seats_sold' => 0,
        'seats_free' => 0,
        'revenue_cents' => 0,
        'currency' => 'EUR',
    ]);
});

it('checks a ticket in and returns exactly the ticket shape', function () use ($fixtureOrganizer): void {
    $response = $this->actingAs($fixtureOrganizer(), 'sanctum')
        ->postJson('/api/v1/organizer/check-in', ['qr_code' => 'A1B2C3D4E5F6'])
        ->assertStatus(200);

    expect(array_keys((array) $response->json()))->toBe(['id', 'order_id', 'event_seat_id', 'qr_code', 'status', 'checked_in_at'])
        ->and($response->json('data'))->toBeNull()
        ->and($response->json('qr_code'))->toBe('A1B2C3D4E5F6')
        ->and($response->json('status'))->toBe('checked_in')
        ->and($response->json('checked_in_at'))->toBe('2026-10-01T19:05:00Z');
});

it('409 ALREADY_CHECKED_IN carrying the first check-in timestamp', function () use ($fixtureOrganizer): void {
    $this->actingAs($fixtureOrganizer(), 'sanctum')
        ->postJson('/api/v1/organizer/check-in', ['qr_code' => 'G7H8J9K0L1M2'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'ALREADY_CHECKED_IN')
        ->assertJsonPath('error.details.checked_in_at', '2026-10-01T18:42:07Z');
});
