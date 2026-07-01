<?php

declare(strict_types=1);

namespace App\Domain\Event\Contracts;

use App\Domain\Event\Events\EventPublished;

interface EventPublisherInterface
{
    public function publish(EventPublished $event): void;
}
