<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\User\Contracts\HasRole;
use App\Domain\User\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureRole
{
    public function handle(Request $request, Closure $next, string $role): Response
    {
        $user = $request->user();
        $required = UserRole::tryFrom($role);

        if ($required === null || ! $user instanceof HasRole || ! $user->hasRole($required)) {
            abort(403);
        }

        return $next($request);
    }
}
