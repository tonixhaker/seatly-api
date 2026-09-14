<?php

declare(strict_types=1);

namespace App\Http\Exceptions;

use App\Domain\Shared\Enums\ErrorCode;
use App\Domain\Shared\Exceptions\DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

final class ApiExceptionRenderer
{
    public static function render(Throwable $e, Request $request): ?JsonResponse
    {
        if (! $request->expectsJson() && ! $request->is('api/*')) {
            return null;
        }

        return match (true) {
            $e instanceof HttpResponseException => null,
            $e instanceof DomainException => self::envelope($e->errorCode(), $e->getMessage(), $e->details),
            $e instanceof ValidationException => self::envelope(ErrorCode::VALIDATION_FAILED, $e->getMessage(), $e->errors()),
            $e instanceof AuthenticationException => self::envelope(ErrorCode::UNAUTHENTICATED, 'Authentication is required to access this resource.'),
            $e instanceof AuthorizationException, $e instanceof AccessDeniedHttpException => self::envelope(ErrorCode::FORBIDDEN, 'You are not allowed to perform this action.'),
            $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => self::envelope(ErrorCode::NOT_FOUND, 'The requested resource was not found.'),
            default => self::envelope(ErrorCode::INTERNAL_ERROR, 'An unexpected error occurred.'),
        };
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private static function envelope(ErrorCode $code, string $message, array $details = []): JsonResponse
    {
        $error = [
            'code' => $code->value,
            'message' => $message,
        ];

        if ($details !== []) {
            $error['details'] = $details;
        }

        return new JsonResponse(['error' => $error], $code->status());
    }
}
