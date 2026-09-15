<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\User\DTO\LoginCredentials;
use App\Domain\User\DTO\RegisterUserData;
use App\Domain\User\Enums\UserRole;
use App\Domain\User\Services\AuthService;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class AuthController extends Controller
{
    public function __construct(private readonly AuthService $auth) {}

    /**
     * Register a new buyer or organizer account.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $result = $this->auth->register(new RegisterUserData(
            $request->string('name')->toString(),
            $request->string('email')->toString(),
            $request->string('password')->toString(),
            UserRole::from($request->string('role')->toString()),
        ));

        return response()->json([
            'token' => $result->token,
            'user' => new UserResource($result->user),
        ], 201);
    }

    /**
     * Exchange credentials for a bearer token.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->auth->login(new LoginCredentials(
            $request->string('email')->toString(),
            $request->string('password')->toString(),
        ));

        return response()->json([
            'token' => $result->token,
            'user' => new UserResource($result->user),
        ]);
    }

    /**
     * Revoke the current bearer token.
     */
    public function logout(Request $request): Response
    {
        $this->auth->logout($request->user());

        return response()->noContent();
    }

    /**
     * Return the authenticated user.
     */
    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }
}
