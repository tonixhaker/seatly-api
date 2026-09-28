<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

use App\Domain\Shared\Contracts\DomainEvent;
use App\Domain\Shared\Contracts\EventPublisherInterface;
use Psr\Log\LoggerInterface;

final readonly class LoggingEventPublisher implements EventPublisherInterface
{
    public function __construct(
        private LoggerInterface $logger,
        private EnvelopeSchemaValidator $validator,
    ) {}

    public function publish(DomainEvent $event): void
    {
        $envelope = EventEnvelope::for($event);

        $this->validator->assertValid($envelope);

        $this->logger->info($envelope->event_type, [
            'event_type' => $envelope->event_type,
            'event_id' => $envelope->event_id,
        ]);
    }
}
