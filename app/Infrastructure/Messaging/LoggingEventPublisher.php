<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

use App\Domain\Event\Contracts\EventPublisherInterface;
use App\Domain\Event\Events\EventPublished;
use Psr\Log\LoggerInterface;

final class LoggingEventPublisher implements EventPublisherInterface
{
    public function __construct(private readonly LoggerInterface $logger) {}

    public function publish(EventPublished $event): void
    {
        $this->logger->info(EventPublished::TYPE, [
            'event_id' => $event->event_id,
            'seat_ids' => $event->seat_ids,
        ]);
    }
}
