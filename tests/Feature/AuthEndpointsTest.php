<?php

declare(strict_types=1);

use App\Domain\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function registerPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'password' => 'correct-horse',
        'role' => 'buyer',
    ], $overrides);
}

it('rejects registration without a role and names the field in details', function (): void {
    $payload = registerPayload();
    unset($payload['role']);

    $this->postJson('/api/v1/auth/register', $payload)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED')
        ->assertJsonStructure(['error' => ['details' => ['role']]]);
});

it('rejects registration with a role outside buyer and organizer', function (): void {
    $this->postJson('/api/v1/auth/register', registerPayload(['role' => 'admin']))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED')
        ->assertJsonStructure(['error' => ['details' => ['role']]]);
});

it('rejects registration with a password shorter than eight characters', function (): void {
    $this->postJson('/api/v1/auth/register', registerPayload(['password' => 'sevench']))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED')
        ->assertJsonStructure(['error' => ['details' => ['password']]]);
});

it('rejects registration with an email that already exists', function (): void {
    $existing = User::factory()->create();

    $this->postJson('/api/v1/auth/register', registerPayload(['email' => $existing->email]))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED')
        ->assertJsonStructure(['error' => ['details' => ['email']]]);
});

it('registers a valid payload and returns a token with the four user fields', function (): void {
    $response = $this->postJson('/api/v1/auth/register', registerPayload())
        ->assertStatus(201);

    expect(array_keys((array) $response->json()))->toBe(['token', 'user'])
        ->and(array_keys((array) $response->json('user')))->toBe(['id', 'name', 'email', 'role'])
        ->and($response->json('token'))->toBeString()->not->toBeEmpty();
});

it('never echoes a password or a password hash on register', function (): void {
    $response = $this->postJson('/api/v1/auth/register', registerPayload())
        ->assertStatus(201);

    expect($response->getContent())
        ->not->toContain('password')
        ->not->toContain('$2y$');
});

it('logs in a valid payload and returns a token with the four user fields', function (): void {
    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'ada@example.com',
        'password' => 'correct-horse',
    ])->assertStatus(200);

    expect(array_keys((array) $response->json()))->toBe(['token', 'user'])
        ->and(array_keys((array) $response->json('user')))->toBe(['id', 'name', 'email', 'role'])
        ->and($response->getContent())
        ->not->toContain('password')
        ->not->toContain('$2y$');
});

it('rejects a login without an email', function (): void {
    $this->postJson('/api/v1/auth/login', ['password' => 'correct-horse'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED')
        ->assertJsonStructure(['error' => ['details' => ['email']]]);
});

it('rejects an unauthenticated request to the current user route', function (): void {
    $this->getJson('/api/v1/me')
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'UNAUTHENTICATED');
});

it('rejects an unauthenticated logout', function (): void {
    $this->postJson('/api/v1/auth/logout')
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'UNAUTHENTICATED');
});

it('returns the current user unwrapped for an authenticated request', function (): void {
    $response = $this->actingAs(User::factory()->create(), 'sanctum')
        ->getJson('/api/v1/me')
        ->assertStatus(200);

    expect(array_keys((array) $response->json()))->toBe(['id', 'name', 'email', 'role'])
        ->and($response->json('data'))->toBeNull()
        ->and($response->getContent())
        ->not->toContain('password')
        ->not->toContain('$2y$');
});

it('logs out an authenticated user with an empty 204', function (): void {
    $response = $this->actingAs(User::factory()->create(), 'sanctum')
        ->postJson('/api/v1/auth/logout')
        ->assertStatus(204);

    expect($response->getContent())->toBe('');
});

it('maps a wrong HTTP verb on an existing route to METHOD_NOT_ALLOWED', function (): void {
    $this->getJson('/api/v1/auth/register')
        ->assertStatus(405)
        ->assertJsonPath('error.code', 'METHOD_NOT_ALLOWED');
});

it('rejects an unresolvable bearer token with UNAUTHENTICATED rather than a server error', function (): void {
    $this->getJson('/api/v1/me', ['Authorization' => 'Bearer 1|notarealtoken'])
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'UNAUTHENTICATED');

    $this->postJson('/api/v1/auth/logout', [], ['Authorization' => 'Bearer 1|notarealtoken'])
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'UNAUTHENTICATED');
});
