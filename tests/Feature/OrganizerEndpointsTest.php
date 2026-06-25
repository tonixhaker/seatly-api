<?php

declare(strict_types=1);

use App\Domain\User\Enums\UserRole;
use App\Models\User;

function organizerUser(): User
{
    return User::factory()->make(['id' => 10, 'role' => 'organizer']);
}

function eventPayload(array $overrides = []): array
{
    return array_merge([
        'venue_id' => 1,
        'title' => 'Late Night Strings',
        'description' => 'A short programme of chamber works.',
        'starts_at' => '2027-05-01T19:00:00Z',
    ], $overrides);
}

dataset('organizerRoutes', [
    'POST /organizer/events' => ['post', '/api/v1/organizer/events'],
    'PUT /organizer/events/{id}' => ['put', '/api/v1/organizer/events/3'],
    'POST /organizer/events/{id}/publish' => ['post', '/api/v1/organizer/events/3/publish'],
    'GET /organizer/events/{id}/stats' => ['get', '/api/v1/organizer/events/1/stats'],
    'POST /organizer/check-in' => ['post', '/api/v1/organizer/check-in'],
]);

it('factory make keeps the id and casts the role to the UserRole enum', function (): void {
    expect(organizerUser()->getAttribute('id'))->toBe(10)
        ->and(organizerUser()->getAttribute('role'))->toBe(UserRole::Organizer);
});

it('401 without a token on every organizer route, with no details key', function (string $method, string $uri): void {
    $response = $this->json($method, $uri, eventPayload(['qr_code' => 'A1B2C3D4E5F6']))
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'UNAUTHENTICATED');

    expect(array_keys((array) $response->json('error')))->toBe(['code', 'message']);
})->with('organizerRoutes');

it('403 on every organizer route for a buyer, a role-less user and a wrong-case role', function (string $method, string $uri): void {
    foreach ([['role' => 'buyer'], ['role' => null]] as $attributes) {
        $response = $this->actingAs(User::factory()->make($attributes), 'sanctum')
            ->json($method, $uri, eventPayload(['qr_code' => 'A1B2C3D4E5F6']))
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');

        expect(array_keys((array) $response->json('error')))->toBe(['code', 'message']);
    }
})->with('organizerRoutes');

it('another organizer event is 404 never 403', function (string $method, string $uri): void {
    $response = $this->actingAs(organizerUser(), 'sanctum')
        ->json($method, $uri, eventPayload())
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'NOT_FOUND');

    expect($response->getStatusCode())->not->toBe(403);
})->with([
    'update' => ['put', '/api/v1/organizer/events/2'],
    'publish' => ['post', '/api/v1/organizer/events/2/publish'],
    'stats' => ['get', '/api/v1/organizer/events/2/stats'],
]);

it('a ticket for another organizer event is 404 never 403', function (): void {
    $response = $this->actingAs(organizerUser(), 'sanctum')
        ->postJson('/api/v1/organizer/check-in', ['qr_code' => 'N3P4Q5R6S7T8'])
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'NOT_FOUND');

    expect($response->getStatusCode())->not->toBe(403);
});

it('a foreign event, an unknown id and an oversized id are indistinguishable on stats', function (): void {
    $bodies = [];

    foreach (['2', '999', '12345678901234567890'] as $id) {
        $response = $this->actingAs(organizerUser(), 'sanctum')
            ->getJson('/api/v1/organizer/events/'.$id.'/stats')
            ->assertStatus(404);

        $bodies[] = $response->getContent();
    }

    expect($bodies[0])->toBe('{"error":{"code":"NOT_FOUND","message":"The requested resource was not found."}}')
        ->and($bodies[1])->toBe($bodies[0])
        ->and($bodies[2])->toBe($bodies[0]);
});

it('creates a draft event with exactly the detail shape', function (): void {
    $response = $this->actingAs(organizerUser(), 'sanctum')
        ->postJson('/api/v1/organizer/events', eventPayload())
        ->assertStatus(201);

    expect(array_keys((array) $response->json()))->toBe(['id', 'title', 'description', 'starts_at', 'status', 'venue'])
        ->and($response->json('id'))->toBe(5)
        ->and($response->json('status'))->toBe('draft')
        ->and($response->json('title'))->toBe('Late Night Strings')
        ->and($response->json('description'))->toBe('A short programme of chamber works.')
        ->and($response->json('starts_at'))->toBe('2027-05-01T19:00:00Z')
        ->and($response->json('venue'))->toBe([
            'id' => 1,
            'name' => 'Riverside Arena',
            'address' => '14 Quay Street',
            'city' => 'Rotterdam',
        ]);
});

it('ignores a client supplied status on create', function (): void {
    $this->actingAs(organizerUser(), 'sanctum')
        ->postJson('/api/v1/organizer/events', eventPayload(['status' => 'published']))
        ->assertStatus(201)
        ->assertJsonPath('status', 'draft');
});

it('422 naming the field the create rules rejected', function (array $overrides, string $field): void {
    $this->actingAs(organizerUser(), 'sanctum')
        ->postJson('/api/v1/organizer/events', eventPayload($overrides))
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

it('422 naming the field the update rules rejected', function (array $overrides, string $field): void {
    $this->actingAs(organizerUser(), 'sanctum')
        ->putJson('/api/v1/organizer/events/3', eventPayload($overrides))
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

it('422 naming a required field missing from create and from update', function (string $field): void {
    $payload = eventPayload();
    unset($payload[$field]);

    $this->actingAs(organizerUser(), 'sanctum')
        ->postJson('/api/v1/organizer/events', $payload)
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['details' => [$field]]]);

    $this->actingAs(organizerUser(), 'sanctum')
        ->putJson('/api/v1/organizer/events/3', $payload)
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with(['venue_id', 'title', 'starts_at']);

it('accepts the boundary values on create and on update', function (array $overrides): void {
    $this->actingAs(organizerUser(), 'sanctum')
        ->postJson('/api/v1/organizer/events', eventPayload($overrides))
        ->assertStatus(201);

    $this->actingAs(organizerUser(), 'sanctum')
        ->putJson('/api/v1/organizer/events/3', eventPayload($overrides))
        ->assertStatus(200);
})->with([
    'zulu starts_at' => [['starts_at' => '2027-05-01T19:00:00Z']],
    'offset starts_at' => [['starts_at' => '2027-05-01T19:00:00+02:00']],
    'title of exactly 255 characters' => [['title' => str_repeat('t', 255)]],
    'description of exactly 2000 characters' => [['description' => str_repeat('d', 2000)]],
]);

it('accepts a payload with no description at all', function (): void {
    $payload = eventPayload();
    unset($payload['description']);

    $this->actingAs(organizerUser(), 'sanctum')
        ->postJson('/api/v1/organizer/events', $payload)
        ->assertStatus(201);

    $this->actingAs(organizerUser(), 'sanctum')
        ->putJson('/api/v1/organizer/events/3', $payload)
        ->assertStatus(200);
});

it('422 for a qr code of the wrong length, the wrong type or none at all', function (array $payload): void {
    $this->actingAs(organizerUser(), 'sanctum')
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

it('404 for a well formed qr code that matches no ticket', function (): void {
    $this->actingAs(organizerUser(), 'sanctum')
        ->postJson('/api/v1/organizer/check-in', ['qr_code' => 'Z9Y8X7W6V5U4'])
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'NOT_FOUND');
});

it('updates a draft without moving it out of draft', function (): void {
    $this->actingAs(organizerUser(), 'sanctum')
        ->putJson('/api/v1/organizer/events/3', eventPayload())
        ->assertStatus(200)
        ->assertJsonPath('status', 'draft')
        ->assertJsonPath('id', 3)
        ->assertJsonPath('title', 'Late Night Strings');
});

it('409 INVALID_STATE_TRANSITION when editing an event that is not a draft', function (int $id, string $status): void {
    $this->actingAs(organizerUser(), 'sanctum')
        ->putJson('/api/v1/organizer/events/'.$id, eventPayload())
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'INVALID_STATE_TRANSITION')
        ->assertJsonPath('error.details.status', $status);
})->with([
    'published' => [1, 'published'],
    'archived' => [4, 'archived'],
]);

it('publishes a draft', function (): void {
    $this->actingAs(organizerUser(), 'sanctum')
        ->postJson('/api/v1/organizer/events/3/publish')
        ->assertStatus(200)
        ->assertJsonPath('id', 3)
        ->assertJsonPath('status', 'published')
        ->assertJsonPath('title', 'Spring Gala')
        ->assertJsonPath('starts_at', '2027-03-04T18:00:00Z');
});

it('409 INVALID_STATE_TRANSITION when publishing an event that is not a draft', function (int $id, string $status): void {
    $this->actingAs(organizerUser(), 'sanctum')
        ->postJson('/api/v1/organizer/events/'.$id.'/publish')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'INVALID_STATE_TRANSITION')
        ->assertJsonPath('error.details.status', $status);
})->with([
    'published' => [1, 'published'],
    'archived' => [4, 'archived'],
]);

it('reports the sales dashboard for an event that has seats', function (int $id): void {
    $response = $this->actingAs(organizerUser(), 'sanctum')
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

it('reports every counter as zero for a draft that has no seats yet', function (): void {
    $response = $this->actingAs(organizerUser(), 'sanctum')
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

it('checks a ticket in and returns exactly the ticket shape', function (): void {
    $response = $this->actingAs(organizerUser(), 'sanctum')
        ->postJson('/api/v1/organizer/check-in', ['qr_code' => 'A1B2C3D4E5F6'])
        ->assertStatus(200);

    expect(array_keys((array) $response->json()))->toBe(['id', 'order_id', 'event_seat_id', 'qr_code', 'status', 'checked_in_at'])
        ->and($response->json('data'))->toBeNull()
        ->and($response->json('qr_code'))->toBe('A1B2C3D4E5F6')
        ->and($response->json('status'))->toBe('checked_in')
        ->and($response->json('checked_in_at'))->toBe('2026-10-01T19:05:00Z');
});

it('409 ALREADY_CHECKED_IN carrying the first check-in timestamp', function (): void {
    $this->actingAs(organizerUser(), 'sanctum')
        ->postJson('/api/v1/organizer/check-in', ['qr_code' => 'G7H8J9K0L1M2'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'ALREADY_CHECKED_IN')
        ->assertJsonPath('error.details.checked_in_at', '2026-10-01T18:42:07Z');
});

it('keeps the organizer fixtures in step with the public catalogue', function (): void {
    $this->actingAs(organizerUser(), 'sanctum')
        ->getJson('/api/v1/organizer/events/1/stats')
        ->assertStatus(200)
        ->assertJsonPath('event_id', 1);

    $this->getJson('/api/v1/events/1')
        ->assertStatus(200)
        ->assertJsonPath('title', 'Autumn Symphony')
        ->assertJsonPath('starts_at', '2026-10-01T19:00:00Z')
        ->assertJsonPath('status', 'published');
});

it('hostile input never 500s', function (string $method, string $uri, array $payload): void {
    $status = $this->actingAs(organizerUser(), 'sanctum')->json($method, $uri, $payload)->getStatusCode();

    expect($status)->toBe(422)->not->toBe(500);
})->with([
    'array title on create' => ['post', '/api/v1/organizer/events', eventPayload(['title' => ['a']])],
    'array venue_id on create' => ['post', '/api/v1/organizer/events', eventPayload(['venue_id' => [1]])],
    'array starts_at on create' => ['post', '/api/v1/organizer/events', eventPayload(['starts_at' => ['2027-05-01T19:00:00Z']])],
    'array title on update' => ['put', '/api/v1/organizer/events/3', eventPayload(['title' => ['a']])],
    'array venue_id on update' => ['put', '/api/v1/organizer/events/3', eventPayload(['venue_id' => [1]])],
    'array starts_at on update' => ['put', '/api/v1/organizer/events/3', eventPayload(['starts_at' => ['2027-05-01T19:00:00Z']])],
    'array qr_code on check-in' => ['post', '/api/v1/organizer/check-in', ['qr_code' => ['A1B2C3D4E5F6']]],
]);

it('405 METHOD_NOT_ALLOWED on a wrong verb against the update route', function (): void {
    $this->actingAs(organizerUser(), 'sanctum')
        ->patchJson('/api/v1/organizer/events/3', eventPayload())
        ->assertStatus(405)
        ->assertJsonPath('error.code', 'METHOD_NOT_ALLOWED');
});

it('rejects a starts_at whose UTC offset cannot exist', function (string $startsAt, int $status): void {
    $this->actingAs(organizerUser(), 'sanctum')
        ->postJson('/api/v1/organizer/events', eventPayload(['starts_at' => $startsAt]))
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

it('rejects control characters in title and a nul byte in description', function (array $overrides, int $status): void {
    $this->actingAs(organizerUser(), 'sanctum')
        ->postJson('/api/v1/organizer/events', eventPayload($overrides))
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

it('applies the same starts_at and title rules on update', function (): void {
    $this->actingAs(organizerUser(), 'sanctum')
        ->putJson('/api/v1/organizer/events/3', eventPayload(['starts_at' => '2027-05-01T19:00:00+25:00']))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED');

    $this->actingAs(organizerUser(), 'sanctum')
        ->putJson('/api/v1/organizer/events/3', eventPayload(['title' => "a\u{0000}b"]))
        ->assertStatus(422);
});
