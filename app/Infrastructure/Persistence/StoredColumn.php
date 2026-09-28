<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use Illuminate\Support\Carbon;
use RuntimeException;

final class StoredColumn
{
    public static function number(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : throw new RuntimeException('A stored numeric column is not numeric.');
    }

    public static function text(mixed $value): string
    {
        return is_string($value) ? $value : throw new RuntimeException('A stored text column is not a string.');
    }

    public static function timestamp(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return is_string($value)
            ? Carbon::parse($value, 'UTC')->toIso8601ZuluString()
            : throw new RuntimeException('A stored timestamp column is not a string.');
    }
}
