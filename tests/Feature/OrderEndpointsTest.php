<?php

declare(strict_types=1);

use App\Models\User;

function buyerUser(): User
{
    return User::factory()->make(['role' => 'buyer']);
}

function orderPayload(array $overrides = []): array
{
    return array_merge([
        'event_id' => 1,
        'seat_ids' => [1, 2],
        'session_id' => '11111111-2222-4333-8444-555555555555',
        'idempotency_key' => 'key-abc',
    ], $overrides);
}

it('factory make keeps the role attribute', function (): void {
    expect(buyerUser()->getAttribute('role'))->toBe('buyer');
});

it('401 without a token', function (): void {
    $this->postJson('/api/v1/orders', orderPayload())->assertStatus(401)->assertJsonPath('error.code', 'UNAUTHENTICATED');
    $this->getJson('/api/v1/my/tickets')->assertStatus(401);
});

it('403 for an organizer and a role-less user', function (): void {
    $this->actingAs(User::factory()->make(['role' => 'organizer']), 'sanctum')
        ->getJson('/api/v1/my/tickets')->assertStatus(403)->assertJsonPath('error.code', 'FORBIDDEN');

    $this->actingAs(User::factory()->make(), 'sanctum')
        ->postJson('/api/v1/orders', orderPayload())->assertStatus(403)->assertJsonPath('error.code', 'FORBIDDEN');
});

it('201 with the exact order shape and no session_id', function (): void {
    $response = $this->actingAs(buyerUser(), 'sanctum')->postJson('/api/v1/orders', orderPayload())->assertStatus(201);

    expect(array_keys((array) $response->json()))->toBe(['id', 'event_id', 'status', 'total_cents', 'currency', 'items'])
        ->and($response->json('status'))->toBe('paid')
        ->and($response->json('total_cents'))->toBe(10000)
        ->and($response->json('items'))->toBe([
            ['event_seat_id' => 1, 'price_cents' => 5000],
            ['event_seat_id' => 2, 'price_cents' => 5000],
        ])
        ->and($response->getContent())->not->toContain('session_id')
        ->not->toContain('11111111-2222-4333-8444-555555555555');
});

it('SEATS_NOT_HELD reports only the missing seats', function (): void {
    $this->actingAs(buyerUser(), 'sanctum')
        ->postJson('/api/v1/orders', orderPayload(['seat_ids' => [1, 3, 7]]))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'SEATS_NOT_HELD')
        ->assertJsonPath('error.details.seats', [3, 7]);
});

it('validation failures', function (array $overrides, string $field): void {
    $this->actingAs(buyerUser(), 'sanctum')
        ->postJson('/api/v1/orders', orderPayload($overrides))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED')
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with([
    'empty seat_ids' => [['seat_ids' => []], 'seat_ids'],
    'non-uuid session_id' => [['session_id' => 'not-a-uuid'], 'session_id'],
    'duplicate seat ids' => [['seat_ids' => [1, 1]], 'seat_ids.1'],
]);

it('ownership is 404 never 403', function (): void {
    $own = $this->actingAs(buyerUser(), 'sanctum')->getJson('/api/v1/orders/3f1b8c42-5d6e-4a7b-9c10-2e4f6a8b0d13');
    $own->assertStatus(200);

    $foreign = $this->actingAs(buyerUser(), 'sanctum')->getJson('/api/v1/orders/7c2d9e50-1a3b-4c5d-8e9f-0b1c2d3e4f56');
    $foreign->assertStatus(404)->assertJsonPath('error.code', 'NOT_FOUND');
    expect($foreign->getStatusCode())->not->toBe(403);

    $this->actingAs(buyerUser(), 'sanctum')->getJson('/api/v1/orders/abc')->assertStatus(404);
});

it('tickets is a bare array with both statuses', function (): void {
    $response = $this->actingAs(buyerUser(), 'sanctum')->getJson('/api/v1/my/tickets')->assertStatus(200);

    expect($response->json('data'))->toBeNull()
        ->and($response->json())->toHaveCount(2)
        ->and(array_keys((array) $response->json('0')))->toBe(['id', 'order_id', 'event_seat_id', 'qr_code', 'status', 'checked_in_at'])
        ->and($response->json('0.status'))->toBe('issued')
        ->and($response->json('0.checked_in_at'))->toBeNull()
        ->and($response->json('1.status'))->toBe('checked_in')
        ->and($response->json('1.checked_in_at'))->toBe('2026-10-01T18:42:07Z')
        ->and($response->getContent())->not->toContain('session_id');
});

it('hostile input never 500s', function (mixed $overrides): void {
    $status = $this->actingAs(buyerUser(), 'sanctum')->postJson('/api/v1/orders', orderPayload($overrides))->getStatusCode();

    expect($status)->toBe(422);
})->with([
    [['event_id' => '99999999999999999999']],
    [['event_id' => 'abc']],
    [['seat_ids' => 'nope']],
    [['seat_ids' => [['nested']]]],
    [['seat_ids' => ['x']]],
    [['session_id' => ['a']]],
    [['idempotency_key' => ['a']]],
]);

dataset('buyerRoutes', [
    'POST /orders' => ['post', '/api/v1/orders'],
    'GET /orders/{id}' => ['get', '/api/v1/orders/3f1b8c42-5d6e-4a7b-9c10-2e4f6a8b0d13'],
    'GET /my/tickets' => ['get', '/api/v1/my/tickets'],
]);

it('401 without a token on every buyer route, with no details key', function (string $method, string $uri): void {
    $response = $this->json($method, $uri, orderPayload())
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'UNAUTHENTICATED');

    expect(array_keys((array) $response->json('error')))->toBe(['code', 'message']);
})->with('buyerRoutes');

it('403 on every buyer route for every non-buyer, with no details key', function (string $method, string $uri): void {
    foreach ([['role' => 'organizer'], ['role' => 'admin'], ['role' => 'BUYER'], ['role' => ''], []] as $attributes) {
        $response = $this->actingAs(User::factory()->make($attributes), 'sanctum')
            ->json($method, $uri, orderPayload())
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');

        expect(array_keys((array) $response->json('error')))->toBe(['code', 'message']);
    }
})->with('buyerRoutes');

it('422 naming the field when a required field is missing', function (string $field): void {
    $payload = orderPayload();
    unset($payload[$field]);

    $this->actingAs(buyerUser(), 'sanctum')
        ->postJson('/api/v1/orders', $payload)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED')
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with(['event_id', 'seat_ids', 'session_id', 'idempotency_key']);

it('422 naming the field the rule rejected', function (array $overrides, string $field): void {
    $this->actingAs(buyerUser(), 'sanctum')
        ->postJson('/api/v1/orders', orderPayload($overrides))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED')
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with([
    'non-integer seat id' => [['seat_ids' => ['x']], 'seat_ids.0'],
    'decimal seat id' => [['seat_ids' => [1.5]], 'seat_ids.0'],
    'zero seat id' => [['seat_ids' => [0]], 'seat_ids.0'],
    'negative seat id' => [['seat_ids' => [-5]], 'seat_ids.0'],
    'zero event_id' => [['event_id' => 0], 'event_id'],
    'seat_ids not an array' => [['seat_ids' => 'nope'], 'seat_ids'],
    'idempotency_key over 128 characters' => [['idempotency_key' => str_repeat('k', 129)], 'idempotency_key'],
]);

it('accepts an idempotency key of exactly 128 characters', function (): void {
    $this->actingAs(buyerUser(), 'sanctum')
        ->postJson('/api/v1/orders', orderPayload(['idempotency_key' => str_repeat('k', 128)]))
        ->assertStatus(201);
});

it('405 METHOD_NOT_ALLOWED on a wrong verb against a buyer route', function (): void {
    $this->getJson('/api/v1/orders')
        ->assertStatus(405)
        ->assertJsonPath('error.code', 'METHOD_NOT_ALLOWED');

    $this->deleteJson('/api/v1/orders/3f1b8c42-5d6e-4a7b-9c10-2e4f6a8b0d13')
        ->assertStatus(405)
        ->assertJsonPath('error.code', 'METHOD_NOT_ALLOWED');
});

it('404 with no details key for an uppercase variant of the only known order id', function (): void {
    $response = $this->actingAs(buyerUser(), 'sanctum')
        ->getJson('/api/v1/orders/3F1B8C42-5D6E-4A7B-9C10-2E4F6A8B0D13')
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'NOT_FOUND');

    expect(array_keys((array) $response->json('error')))->toBe(['code', 'message']);
});

it('never echoes the session id on any of the three routes', function (): void {
    $sessionId = '11111111-2222-4333-8444-555555555555';

    $responses = [
        $this->actingAs(buyerUser(), 'sanctum')->postJson('/api/v1/orders', orderPayload(['session_id' => $sessionId])),
        $this->actingAs(buyerUser(), 'sanctum')->getJson('/api/v1/orders/3f1b8c42-5d6e-4a7b-9c10-2e4f6a8b0d13'),
        $this->actingAs(buyerUser(), 'sanctum')->getJson('/api/v1/my/tickets'),
    ];

    foreach ($responses as $response) {
        expect($response->getContent())->not->toContain('session_id')->not->toContain($sessionId);
    }
});

it('issues a unique twelve character qr code per ticket', function (): void {
    $codes = (array) $this->actingAs(buyerUser(), 'sanctum')->getJson('/api/v1/my/tickets')->json('*.qr_code');

    expect($codes)->toHaveCount(2)
        ->and(array_unique($codes))->toHaveCount(count($codes));

    foreach ($codes as $code) {
        expect($code)->toBeString()->toHaveLength(12);
    }
});

it('rejects a boolean seat id instead of fabricating seat zero', function (): void {
    $this->actingAs(buyerUser(), 'sanctum')
        ->postJson('/api/v1/orders', orderPayload(['seat_ids' => [true]]))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED')
        ->assertJsonStructure(['error' => ['details' => ['seat_ids.0']]]);
});

it('rejects a boolean event id', function (): void {
    $this->actingAs(buyerUser(), 'sanctum')
        ->postJson('/api/v1/orders', orderPayload(['event_id' => true]))
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['details' => ['event_id']]]);
});

it('rejects a string-keyed seat_ids object so error keys stay positional', function (): void {
    $this->actingAs(buyerUser(), 'sanctum')
        ->postJson('/api/v1/orders', orderPayload(['seat_ids' => ['a' => 1, 'b' => 2]]))
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['details' => ['seat_ids']]]);
});

it('caps seat_ids at fifty and stays fast past the cap', function (): void {
    $this->actingAs(buyerUser(), 'sanctum')
        ->postJson('/api/v1/orders', orderPayload(['seat_ids' => range(100, 149)]))
        ->assertStatus(201);

    $this->actingAs(buyerUser(), 'sanctum')
        ->postJson('/api/v1/orders', orderPayload(['seat_ids' => range(100, 150)]))
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['details' => ['seat_ids']]]);
});
