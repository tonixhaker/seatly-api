<?php

declare(strict_types=1);

namespace App\Domain\User\Services;

use App\Domain\User\DTO\AuthResult;
use App\Domain\User\DTO\LoginCredentials;
use App\Domain\User\DTO\RegisterUserData;
use App\Domain\User\DTO\UserData;
use App\Domain\User\Exceptions\InvalidCredentialsException;
use App\Domain\User\Models\User;
use App\Domain\User\Repositories\UserRepositoryInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Hashing\Hasher;
use Laravel\Sanctum\PersonalAccessToken;

final readonly class AuthService
{
    public function __construct(
        private UserRepositoryInterface $users,
        private Hasher $hasher,
    ) {}

    public function register(RegisterUserData $data): AuthResult
    {
        $user = new User;
        $user->fill([
            'name' => $data->name,
            'email' => $data->email,
            'password' => $data->password,
            'role' => $data->role,
        ]);

        $this->users->persist($user);

        return $this->issue($user);
    }

    public function login(LoginCredentials $credentials): AuthResult
    {
        $user = $this->users->findByEmail($credentials->email);

        if ($user === null || ! $this->hasher->check($credentials->password, $user->password)) {
            throw new InvalidCredentialsException;
        }

        return $this->issue($user);
    }

    public function logout(?Authenticatable $user): void
    {
        $token = $user instanceof User ? $user->currentAccessToken() : null;

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }
    }

    private function issue(User $user): AuthResult
    {
        return new AuthResult($user->createToken('api')->plainTextToken, UserData::fromModel($user));
    }
}
