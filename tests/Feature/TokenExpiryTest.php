<?php

declare(strict_types=1);

use App\Domain\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function loginForExpiry(object $test): string
{
    User::factory()->create(['email' => 'ada@example.com', 'password' => 'correct-horse']);

    $token = $test->postJson('/api/v1/auth/login', [
        'email' => 'ada@example.com',
        'password' => 'correct-horse',
    ])->assertStatus(200)->json('token');

    expect($token)->toBeString()->not->toBeEmpty();

    return $token;
}

function storedExpiresAt(): mixed
{
    return DB::table('personal_access_tokens')->value('expires_at');
}

function getMe(object $test, string $token): mixed
{
    app('auth')->forgetGuards();

    return $test->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$token]);
}

it('accepts a freshly issued login token on GET /me', function (): void {
    $this->freezeSecond();

    getMe($this, loginForExpiry($this))
        ->assertStatus(200)
        ->assertJsonPath('email', 'ada@example.com');
});

it('stores expires_at as issue time plus 120 minutes on login', function (): void {
    $now = $this->freezeSecond();

    loginForExpiry($this);

    expect(DB::table('personal_access_tokens')->count())->toBe(1)
        ->and(storedExpiresAt())->toBe($now->copy()->addMinutes(120)->format('Y-m-d H:i:s'));
});

it('stores expires_at as issue time plus the configured ttl', function (): void {
    $now = $this->freezeSecond();
    config(['auth.token_ttl_minutes' => 5]);

    loginForExpiry($this);

    expect(storedExpiresAt())->toBe($now->copy()->addMinutes(5)->format('Y-m-d H:i:s'));
});

it('reads the token ttl from SANCTUM_TOKEN_TTL_MINUTES', function (): void {
    expect(config('auth.token_ttl_minutes'))->toBe(120);
});

it('defaults the token ttl to 120 minutes when SANCTUM_TOKEN_TTL_MINUTES is absent', function (): void {
    $saved = [$_SERVER['SANCTUM_TOKEN_TTL_MINUTES'] ?? null, $_ENV['SANCTUM_TOKEN_TTL_MINUTES'] ?? null, getenv('SANCTUM_TOKEN_TTL_MINUTES')];

    unset($_SERVER['SANCTUM_TOKEN_TTL_MINUTES'], $_ENV['SANCTUM_TOKEN_TTL_MINUTES']);
    putenv('SANCTUM_TOKEN_TTL_MINUTES');

    try {
        $config = require config_path('auth.php');
    } finally {
        if ($saved[0] !== null) {
            $_SERVER['SANCTUM_TOKEN_TTL_MINUTES'] = $saved[0];
        }
        if ($saved[1] !== null) {
            $_ENV['SANCTUM_TOKEN_TTL_MINUTES'] = $saved[1];
        }
        if ($saved[2] !== false) {
            putenv('SANCTUM_TOKEN_TTL_MINUTES='.$saved[2]);
        }
    }

    expect($config['token_ttl_minutes'])->toBe(120)
        ->and(env('SANCTUM_TOKEN_TTL_MINUTES'))->toBe('120');
});

it('keeps a login token valid until expires_at and rejects it once past', function (): void {
    $now = $this->freezeSecond();
    $token = loginForExpiry($this);

    getMe($this, $token)->assertStatus(200);

    $this->travelTo($now->copy()->addMinutes(119));
    getMe($this, $token)->assertStatus(200);

    $this->travelTo($now->copy()->addMinutes(120)->addSecond());
    getMe($this, $token)
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'UNAUTHENTICATED');
});

it('rejects a token whose expires_at was set to the past directly in the table', function (): void {
    $this->freezeSecond();
    $token = loginForExpiry($this);

    getMe($this, $token)->assertStatus(200);

    DB::table('personal_access_tokens')->update(['expires_at' => Carbon::now()->subMinute()->format('Y-m-d H:i:s')]);

    getMe($this, $token)
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'UNAUTHENTICATED');
});

it('expires registration tokens the same way', function (): void {
    $now = $this->freezeSecond();

    $token = $this->postJson('/api/v1/auth/register', [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'password' => 'correct-horse',
        'role' => 'buyer',
    ])->assertStatus(201)->json('token');

    expect($token)->toBeString()
        ->and(storedExpiresAt())->toBe($now->copy()->addMinutes(120)->format('Y-m-d H:i:s'));

    getMe($this, $token)->assertStatus(200);

    $this->travelTo($now->copy()->addMinutes(120)->addSecond());
    getMe($this, $token)
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'UNAUTHENTICATED');
});
