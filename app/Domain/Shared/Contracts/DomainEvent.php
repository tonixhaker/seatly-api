<?php

declare(strict_types=1);

namespace App\Domain\Shared\Contracts;

interface DomainEvent
{
    public function type(): string;

    /**
     * @return array<string, mixed>
     */
    public function payload(): array;
}
