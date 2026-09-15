<?php

declare(strict_types=1);

use App\Domain\User\Models\User;

function driftSpec(): array
{
    $decoded = json_decode((string) file_get_contents(base_path('docs/openapi.json')), true, 512, JSON_THROW_ON_ERROR);

    return is_array($decoded) ? $decoded : [];
}

function driftResolve(mixed $schema): array
{
    $spec = driftSpec();

    while (is_array($schema) && isset($schema['$ref'])) {
        $schema = data_get($spec, str_replace(['#/', '/'], ['', '.'], (string) $schema['$ref']));
    }

    return is_array($schema) ? $schema : [];
}

function driftDocumentedKeys(string $method, string $path, string $status): array
{
    $schema = driftResolve(data_get(driftSpec(), 'paths.'.$path.'.'.$method.'.responses.'.$status.'.content.application/json.schema'));

    if (($schema['type'] ?? null) === 'array') {
        $schema = driftResolve($schema['items'] ?? []);
    }

    return array_keys($schema['properties'] ?? []);
}

function driftActualKeys(array $body): array
{
    $item = array_is_list($body) ? ($body[0] ?? []) : $body;

    return array_keys(is_array($item) ? $item : []);
}

function driftBuyer(): User
{
    return User::factory()->make(['id' => 7, 'role' => 'buyer']);
}

function driftOrganizer(): User
{
    return User::factory()->make(['id' => 10, 'role' => 'organizer']);
}

it('documents exactly the keys GET /events/{id} really returns', function (): void {
    $body = $this->getJson('/api/v1/events/1')->assertOk()->json();

    expect(driftActualKeys($body))->toEqualCanonicalizing(driftDocumentedKeys('get', '/api/v1/events/{id}', '200'));
});

it('documents exactly the keys GET /events/{id}/seats really returns', function (): void {
    $body = $this->getJson('/api/v1/events/1/seats')->assertOk()->json();

    expect(driftActualKeys($body))->toEqualCanonicalizing(driftDocumentedKeys('get', '/api/v1/events/{id}/seats', '200'));
});

it('documents exactly the keys GET /me really returns', function (): void {
    $body = $this->actingAs(driftBuyer(), 'sanctum')->getJson('/api/v1/me')->assertOk()->json();

    expect(driftActualKeys($body))->toEqualCanonicalizing(driftDocumentedKeys('get', '/api/v1/me', '200'));
});

it('documents exactly the keys POST /orders really returns', function (): void {
    $body = $this->actingAs(driftBuyer(), 'sanctum')->postJson('/api/v1/orders', [
        'event_id' => 1,
        'seat_ids' => [1, 2],
        'session_id' => '1f3a2b4c-5d6e-4a7b-8c90-1e2f3a4b5c6d',
        'idempotency_key' => 'drift-key',
    ])->assertCreated()->json();

    expect(driftActualKeys($body))->toEqualCanonicalizing(driftDocumentedKeys('post', '/api/v1/orders', '201'));
});

it('documents exactly the keys GET /my/tickets really returns', function (): void {
    $body = $this->actingAs(driftBuyer(), 'sanctum')->getJson('/api/v1/my/tickets')->assertOk()->json();

    expect(driftActualKeys($body))->toEqualCanonicalizing(driftDocumentedKeys('get', '/api/v1/my/tickets', '200'));
});

it('documents exactly the keys GET /organizer/events/{id}/stats really returns', function (): void {
    $body = $this->actingAs(driftOrganizer(), 'sanctum')->getJson('/api/v1/organizer/events/1/stats')->assertOk()->json();

    expect(driftActualKeys($body))->toEqualCanonicalizing(driftDocumentedKeys('get', '/api/v1/organizer/events/{id}/stats', '200'));
});

it('documents exactly the keys POST /organizer/check-in really returns', function (): void {
    $body = $this->actingAs(driftOrganizer(), 'sanctum')
        ->postJson('/api/v1/organizer/check-in', ['qr_code' => 'A1B2C3D4E5F6'])
        ->assertOk()->json();

    expect(driftActualKeys($body))->toEqualCanonicalizing(driftDocumentedKeys('post', '/api/v1/organizer/check-in', '200'));
});

it('documents the real SEATS_NOT_HELD details payload', function (): void {
    $body = $this->actingAs(driftBuyer(), 'sanctum')->postJson('/api/v1/orders', [
        'event_id' => 1,
        'seat_ids' => [1, 3, 7],
        'session_id' => '1f3a2b4c-5d6e-4a7b-8c90-1e2f3a4b5c6d',
        'idempotency_key' => 'drift-key',
    ])->assertStatus(422)->json();

    $documented = collect(driftResolve(data_get(driftSpec(), 'paths./api/v1/orders.post.responses.422.content.application/json.schema'))['anyOf'] ?? [])
        ->firstWhere('properties.error.properties.code.enum.0', 'SEATS_NOT_HELD');

    expect($body['error']['code'])->toBe('SEATS_NOT_HELD')
        ->and(array_keys($body['error']['details']))
        ->toEqualCanonicalizing(array_keys(data_get($documented, 'properties.error.properties.details.properties')));
});

it('documents the real ALREADY_CHECKED_IN details payload', function (): void {
    $body = $this->actingAs(driftOrganizer(), 'sanctum')
        ->postJson('/api/v1/organizer/check-in', ['qr_code' => 'G7H8J9K0L1M2'])
        ->assertStatus(409)->json();

    $documented = driftResolve(data_get(driftSpec(), 'paths./api/v1/organizer/check-in.post.responses.409.content.application/json.schema'));

    expect($body['error']['code'])->toBe('ALREADY_CHECKED_IN')
        ->and(array_keys($body['error']['details']))
        ->toEqualCanonicalizing(array_keys(data_get($documented, 'properties.error.properties.details.properties')));
});

it('documents the paginated envelope GET /events really returns', function (): void {
    $body = $this->getJson('/api/v1/events')->assertOk()->json();
    $schema = driftResolve(data_get(driftSpec(), 'paths./api/v1/events.get.responses.200.content.application/json.schema'));

    expect(array_keys($body))->toEqualCanonicalizing(array_keys($schema['properties']));
});

it('omits only the known scramble paginator gap from meta.links', function (): void {
    $body = $this->getJson('/api/v1/events')->assertOk()->json();
    $schema = driftResolve(data_get(driftSpec(), 'paths./api/v1/events.get.responses.200.content.application/json.schema'));

    $documented = array_keys(data_get($schema, 'properties.meta.properties.links.items.properties'));
    $real = array_keys($body['meta']['links'][0]);

    expect(array_values(array_diff($real, $documented)))->toBe(['page'])
        ->and(array_values(array_diff($documented, $real)))->toBe([]);
});
