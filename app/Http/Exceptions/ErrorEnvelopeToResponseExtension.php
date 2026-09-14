<?php

declare(strict_types=1);

namespace App\Http\Exceptions;

use App\Domain\Shared\Enums\ErrorCode;
use App\Domain\Shared\Exceptions\DomainException;
use Dedoc\Scramble\Extensions\ExceptionToResponseExtension;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType as OpenApiObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\Type\Generic;
use Dedoc\Scramble\Support\Type\Literal\LiteralIntegerType;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\Type;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use ReflectionClass;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

final class ErrorEnvelopeToResponseExtension extends ExceptionToResponseExtension
{
    public function shouldHandle(Type $type): bool
    {
        return $type instanceof ObjectType
            && $type->isInstanceOf(Throwable::class)
            && self::errorCode($type) instanceof ErrorCode;
    }

    public function toResponse(Type $type): ?Response
    {
        if (! $type instanceof ObjectType || ! ($code = self::errorCode($type)) instanceof ErrorCode) {
            return null;
        }

        return Response::make($code->status())
            ->setDescription($code->value)
            ->setContent('application/json', Schema::fromType(self::envelope($code)));
    }

    private static function errorCode(ObjectType $type): ?ErrorCode
    {
        $name = $type->name;

        return match (true) {
            is_subclass_of($name, DomainException::class) => (new ReflectionClass($name))->newInstanceWithoutConstructor()->errorCode(),
            $type->isInstanceOf(ValidationException::class) => ErrorCode::VALIDATION_FAILED,
            $type->isInstanceOf(AuthenticationException::class) => ErrorCode::UNAUTHENTICATED,
            $type->isInstanceOf(AuthorizationException::class), $type->isInstanceOf(AccessDeniedHttpException::class) => ErrorCode::FORBIDDEN,
            $type->isInstanceOf(ModelNotFoundException::class), $type->isInstanceOf(NotFoundHttpException::class) => ErrorCode::NOT_FOUND,
            $type->isInstanceOf(HttpException::class) => self::fromStatus(self::statusOf($type)),
            default => null,
        };
    }

    private static function statusOf(ObjectType $type): ?int
    {
        if (! $type instanceof Generic) {
            return null;
        }

        $codeType = count($type->templateTypes) > 3
            ? ($type->templateTypes[7] ?? null)
            : ($type->templateTypes[0] ?? null);

        return $codeType instanceof LiteralIntegerType ? $codeType->value : null;
    }

    private static function fromStatus(?int $status): ErrorCode
    {
        return match ($status) {
            403 => ErrorCode::FORBIDDEN,
            404 => ErrorCode::NOT_FOUND,
            405 => ErrorCode::METHOD_NOT_ALLOWED,
            429 => ErrorCode::RATE_LIMITED,
            503 => ErrorCode::SERVICE_UNAVAILABLE,
            default => ErrorCode::INTERNAL_ERROR,
        };
    }

    private static function envelope(ErrorCode $code): OpenApiObjectType
    {
        $error = (new OpenApiObjectType)
            ->addProperty('code', (new StringType)->enum([$code->value]))
            ->addProperty('message', (new StringType)->setDescription('One human-readable sentence, safe to show a user.'))
            ->addProperty('details', self::details($code))
            ->setRequired(['code', 'message']);

        return (new OpenApiObjectType)
            ->addProperty('error', $error)
            ->setRequired(['error']);
    }

    private static function details(ErrorCode $code): OpenApiObjectType
    {
        $details = (new OpenApiObjectType)
            ->setDescription('Omitted entirely when empty.');

        return $code === ErrorCode::VALIDATION_FAILED
            ? $details->additionalProperties((new ArrayType)->setItems(new StringType))
            : $details;
    }
}
