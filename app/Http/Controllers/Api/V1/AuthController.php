<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

final class AuthController extends Controller
{
    private const TOKEN = '1|fixtureplaintextbearertokennotissuedyet';

    public function register(RegisterRequest $request): JsonResponse
    {
        return response()->json(['token' => self::TOKEN, 'user' => self::user()], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        return response()->json(['token' => self::TOKEN, 'user' => self::user()]);
    }

    public function logout(): Response
    {
        return response()->noContent();
    }

    public function me(): UserResource
    {
        return self::user();
    }

    private static function user(): UserResource
    {
        return new UserResource((object) [
            'id' => 1,
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'role' => 'buyer',
        ]);
    }
}
