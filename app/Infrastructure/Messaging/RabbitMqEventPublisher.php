<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

use App\Domain\Shared\Contracts\DomainEvent;
use App\Domain\Shared\Contracts\EventPublisherInterface;
use ErrorException;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPExceptionInterface;
use PhpAmqpLib\Message\AMQPMessage;
use Psr\Log\LoggerInterface;
use RuntimeException;

final readonly class RabbitMqEventPublisher implements EventPublisherInterface
{
    /**
     * @param  callable(): AMQPStreamConnection  $connect
     */
    public function __construct(
        private mixed $connect,
        private string $exchange,
        private EnvelopeSchemaValidator $validator,
        private LoggerInterface $logger,
    ) {}

    public function publish(DomainEvent $event): void
    {
        $envelope = EventEnvelope::for($event);

        $this->validator->assertValid($envelope);

        try {
            $this->send($envelope);
        } catch (AMQPExceptionInterface|ErrorException|RuntimeException $failure) {
            $this->logger->error('The event could not be published.', [
                'event_type' => $envelope->event_type,
                'event_id' => $envelope->event_id,
                'reason' => $failure->getMessage(),
            ]);
        }
    }

    private function send(EventEnvelope $envelope): void
    {
        $connection = ($this->connect)();

        try {
            $channel = $connection->channel();
            $channel->exchange_declare($this->exchange, 'topic', false, true, false);
            $channel->basic_publish(
                new AMQPMessage($envelope->toJson(), [
                    'content_type' => 'application/json',
                    'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                    'message_id' => $envelope->event_id,
                ]),
                $this->exchange,
                $envelope->routingKey(),
            );
            $channel->close();
        } finally {
            $connection->close();
        }
    }
}
