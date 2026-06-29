<?php

declare(strict_types=1);

use App\Domain\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

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

it('registers a valid payload, persists the user and issues exactly one token', function (): void {
    $response = $this->postJson('/api/v1/auth/register', registerPayload())
        ->assertStatus(201);

    $id = $response->json('user.id');

    expect(array_keys((array) $response->json()))->toBe(['token', 'user'])
        ->and(array_keys((array) $response->json('user')))->toBe(['id', 'name', 'email', 'role'])
        ->and($response->json('token'))->toBeString()->not->toBeEmpty()
        ->and($response->json('user.email'))->toBe('ada@example.com')
        ->and($response->json('user.role'))->toBe('buyer')
        ->and(DB::table('users')->where('id', $id)->value('email'))->toBe('ada@example.com')
        ->and(DB::table('personal_access_tokens')->where('tokenable_id', $id)->count())->toBe(1);
});

it('never echoes a password or a password hash on register', function (): void {
    $response = $this->postJson('/api/v1/auth/register', registerPayload())
        ->assertStatus(201);

    expect($response->getContent())
        ->not->toContain('password')
        ->not->toContain('$2y$');
});

it('logs in a valid payload and returns a token with the four user fields', function (): void {
    User::factory()->create(['email' => 'ada@example.com', 'password' => 'correct-horse']);

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
    $user = User::factory()->create();

    $response = $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/me')
        ->assertStatus(200);

    expect(array_keys((array) $response->json()))->toBe(['id', 'name', 'email', 'role'])
        ->and($response->json('id'))->toBe($user->id)
        ->and($response->json('email'))->toBe($user->email)
        ->and($response->json('data'))->toBeNull()
        ->and($response->getContent())
        ->not->toContain('password')
        ->not->toContain('$2y$');
});

it('logs out a session-authenticated user with an empty 204, so a TransientToken is a no-op', function (): void {
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

it('throttles the login route and answers RATE_LIMITED with Retry-After', function (): void {
    foreach (range(1, 10) as $ignored) {
        $this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.com'])
            ->assertStatus(422);
    }

    $this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.com'])
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'RATE_LIMITED')
        ->assertHeader('Retry-After')
        ->assertHeader('X-RateLimit-Limit', '10');
});

it('throttles register and login from one shared per-address bucket', function (): void {
    foreach (range(1, 10) as $ignored) {
        $this->postJson('/api/v1/auth/register', registerPayload());
    }

    $this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.com'])
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'RATE_LIMITED');
});

it('registers, then logs in with those credentials, then identifies the caller through GET /me', function (): void {
    $register = $this->postJson('/api/v1/auth/register', registerPayload())->assertStatus(201);

    $login = $this->postJson('/api/v1/auth/login', [
        'email' => 'ada@example.com',
        'password' => 'correct-horse',
    ])->assertStatus(200);

    $me = $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$login->json('token')])
        ->assertStatus(200);

    expect($login->json('token'))->not->toBe($register->json('token'))
        ->and($me->json())->toBe($login->json('user'))
        ->and($me->json())->toBe($register->json('user'))
        ->and($me->json('email'))->toBe('ada@example.com');
});

it('answers a wrong password and an unknown email with byte-identical bodies', function (): void {
    $this->postJson('/api/v1/auth/register', registerPayload())->assertStatus(201);

    $wrongPassword = $this->postJson('/api/v1/auth/login', [
        'email' => 'ada@example.com',
        'password' => 'not-the-password',
    ])->assertStatus(422);

    $unknownEmail = $this->postJson('/api/v1/auth/login', [
        'email' => 'nobody@example.com',
        'password' => 'not-the-password',
    ])->assertStatus(422);

    $wrongPassword->assertJsonPath('error.code', 'VALIDATION_FAILED')->assertJsonMissingPath('error.details');
    $unknownEmail->assertJsonPath('error.code', 'VALIDATION_FAILED')->assertJsonMissingPath('error.details');

    expect($wrongPassword->getContent())->toBe($unknownEmail->getContent());
});

it('revokes only the token that logged out and leaves the second session working', function (): void {
    $first = $this->postJson('/api/v1/auth/register', registerPayload())->assertStatus(201);

    $second = $this->postJson('/api/v1/auth/login', [
        'email' => 'ada@example.com',
        'password' => 'correct-horse',
    ])->assertStatus(200);

    $id = $first->json('user.id');

    $this->app['auth']->forgetGuards();
    $this->postJson('/api/v1/auth/logout', [], ['Authorization' => 'Bearer '.$first->json('token')])
        ->assertStatus(204);

    $this->app['auth']->forgetGuards();
    $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$second->json('token')])
        ->assertStatus(200)
        ->assertJsonPath('id', $id);

    $this->app['auth']->forgetGuards();
    $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$first->json('token')])
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'UNAUTHENTICATED');

    expect(DB::table('personal_access_tokens')->where('tokenable_id', $id)->count())->toBe(1);
});

it('never puts the submitted password or a hash on the wire', function (): void {
    $register = $this->postJson('/api/v1/auth/register', registerPayload())->assertStatus(201);

    $login = $this->postJson('/api/v1/auth/login', [
        'email' => 'ada@example.com',
        'password' => 'correct-horse',
    ])->assertStatus(200);

    $me = $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$login->json('token')])
        ->assertStatus(200);

    foreach ([$register, $login, $me] as $response) {
        expect($response->getContent())
            ->not->toContain('correct-horse')
            ->not->toContain('$2y$')
            ->not->toContain('$argon');
    }
});
