<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\ErrorCode;
use App\Domain\User\DTO\LoginCredentials;
use App\Domain\User\Exceptions\InvalidCredentialsException;
use App\Domain\User\Models\User;
use App\Domain\User\Repositories\UserRepositoryInterface;
use App\Domain\User\Services\AuthService;
use Illuminate\Contracts\Hashing\Hasher;
use Laravel\Sanctum\TransientToken;

$repository = function (?User $found): UserRepositoryInterface {
    return new class($found) implements UserRepositoryInterface
    {
        public function __construct(private readonly ?User $found) {}

        public function findByEmail(string $email): ?User
        {
            return $this->found;
        }

        public function persist(User $user): void {}
    };
};

$hasher = function (bool $matches): Hasher {
    return new class($matches) implements Hasher
    {
        public function __construct(private readonly bool $matches) {}

        public function info($hashedValue): array
        {
            return [];
        }

        public function make($value, array $options = []): string
        {
            return 'hashed';
        }

        public function check($value, $hashedValue, array $options = []): bool
        {
            return $this->matches;
        }

        public function needsRehash($hashedValue, array $options = []): bool
        {
            return false;
        }
    };
};

$existing = function (): User {
    $user = new User;
    $user->setRawAttributes(['id' => 1, 'name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'stored-hash', 'role' => 'buyer']);

    return $user;
};

it('rejects a login for an unknown email', function () use ($repository, $hasher): void {
    $service = new AuthService($repository(null), $hasher(true));

    expect(fn () => $service->login(new LoginCredentials('nobody@example.com', 'correct-horse')))
        ->toThrow(InvalidCredentialsException::class);
});

it('rejects a login whose password does not match the stored hash', function () use ($repository, $hasher, $existing): void {
    $service = new AuthService($repository($existing()), $hasher(false));

    expect(fn () => $service->login(new LoginCredentials('ada@example.com', 'wrong')))
        ->toThrow(InvalidCredentialsException::class);
});

it('raises the identical failure for an unknown email and a wrong password', function () use ($repository, $hasher, $existing): void {
    $unknownEmail = null;
    $wrongPassword = null;

    try {
        (new AuthService($repository(null), $hasher(true)))->login(new LoginCredentials('nobody@example.com', 'correct-horse'));
    } catch (InvalidCredentialsException $e) {
        $unknownEmail = $e;
    }

    try {
        (new AuthService($repository($existing()), $hasher(false)))->login(new LoginCredentials('ada@example.com', 'wrong'));
    } catch (InvalidCredentialsException $e) {
        $wrongPassword = $e;
    }

    expect($unknownEmail?->getMessage())->toBe($wrongPassword?->getMessage())
        ->and($unknownEmail?->details)->toBe([])
        ->and($wrongPassword?->details)->toBe([]);
});

it('keeps the submitted email and password out of the failure it raises', function () use ($repository, $hasher, $existing): void {
    $service = new AuthService($repository($existing()), $hasher(false));

    try {
        $service->login(new LoginCredentials('ada@example.com', 'correct-horse'));
    } catch (InvalidCredentialsException $e) {
        expect($e->getMessage())
            ->not->toContain('ada@example.com')
            ->not->toContain('correct-horse')
            ->not->toContain('stored-hash')
            ->and($e->details)->toBe([]);
    }
});

it('maps the credential failure to VALIDATION_FAILED and 422', function (): void {
    $exception = new InvalidCredentialsException;

    expect($exception->errorCode())->toBe(ErrorCode::VALIDATION_FAILED)
        ->and($exception->errorCode()->status())->toBe(422);
});

it('treats a logout without an authenticated user as a no-op', function () use ($repository, $hasher): void {
    $service = new AuthService($repository(null), $hasher(true));

    $service->logout(null);

    expect(true)->toBeTrue();
});

it('treats a logout on a session token as a no-op rather than a fatal', function () use ($repository, $hasher, $existing): void {
    $service = new AuthService($repository(null), $hasher(true));

    $user = $existing();
    $user->withAccessToken(new TransientToken);

    $service->logout($user);

    expect($user->currentAccessToken())->toBeInstanceOf(TransientToken::class);
});
