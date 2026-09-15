<?php

declare(strict_types=1);

use App\Domain\User\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('serializes a User without password or remember_token', function (): void {
    $user = User::factory()->create();

    $serialized = $user->toArray();

    expect(array_key_exists('password', $serialized))->toBeFalse()
        ->and(array_key_exists('remember_token', $serialized))->toBeFalse()
        ->and($user->toJson())->not->toContain('$2y$')
        ->and($user->toJson())->not->toContain('remember_token');
});

it('hashes password on assignment, so plaintext never reaches the column', function (): void {
    $user = User::factory()->create(['password' => 'plain-text-secret']);

    $stored = (string) DB::table('users')->where('id', $user->getKey())->value('password');

    expect($stored)->not->toBe('plain-text-secret')
        ->and(Hash::check('plain-text-secret', $stored))->toBeTrue();
});

it('casts role to the UserRole enum after a database round trip', function (): void {
    $user = User::factory()->create(['role' => UserRole::Organizer]);

    expect($user->fresh()?->role)->toBe(UserRole::Organizer)
        ->and(DB::table('users')->where('id', $user->getKey())->value('role'))->toBe('organizer');
});

it('authenticates GET /api/v1/me with a real Sanctum token, while the body is still the milestone-01 fixture', function (): void {
    $user = User::factory()->create();

    $token = $user->createToken('test-token')->plainTextToken;

    $response = $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$token])
        ->assertStatus(200);

    expect(array_keys((array) $response->json()))->toBe(['id', 'name', 'email', 'role'])
        ->and(DB::table('personal_access_tokens')->where('tokenable_id', $user->getKey())->count())->toBe(1);
});

it('rejects a revoked Sanctum token with 401, so the token is what authenticated the request', function (): void {
    $user = User::factory()->create();

    $token = $user->createToken('test-token')->plainTextToken;

    $user->tokens()->delete();

    $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$token])
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'UNAUTHENTICATED');
});
