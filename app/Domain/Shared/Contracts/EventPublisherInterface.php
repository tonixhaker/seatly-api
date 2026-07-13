<?php

declare(strict_types=1);

namespace App\Domain\Shared\Contracts;

interface EventPublisherInterface
{
    public function publish(DomainEvent $event): void;
}
