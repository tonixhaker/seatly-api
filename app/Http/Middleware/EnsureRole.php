<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureRole
{
    public function handle(Request $request, Closure $next, string $role): Response
    {
        if ($request->user()?->getAttribute('role') !== $role) {
            abort(403);
        }

        return $next($request);
    }
}
