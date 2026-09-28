<?php

declare(strict_types=1);

use App\Domain\Shared\Contracts\DomainEvent;
use App\Domain\Shared\Contracts\EventPublisherInterface;
use App\Infrastructure\Messaging\RabbitMqEventPublisher;
use Opis\JsonSchema\Validator;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

$brokerUrl = function (): string {
    $url = getenv('RABBITMQ_TEST_URL');

    if (! is_string($url) || $url === '') {
        test()->markTestSkipped('RABBITMQ_TEST_URL is not set; the broker integration test needs a live RabbitMQ.');
    }

    return $url;
};

$connect = function (string $url): AMQPStreamConnection {
    $parts = parse_url($url);
    $parts = is_array($parts) ? $parts : [];

    return new AMQPStreamConnection(
        is_string($parts['host'] ?? null) ? $parts['host'] : 'localhost',
        is_int($parts['port'] ?? null) ? $parts['port'] : 5672,
        is_string($parts['user'] ?? null) ? $parts['user'] : 'guest',
        is_string($parts['pass'] ?? null) ? $parts['pass'] : 'guest',
        '/',
        false,
        'AMQPLAIN',
        null,
        'en_US',
        5.0,
        5.0,
    );
};

$orderPaid = function (): DomainEvent {
    return new class implements DomainEvent
    {
        public function type(): string
        {
            return 'order.paid';
        }

        /**
         * @return array<string, mixed>
         */
        public function payload(): array
        {
            return [
                'order_id' => '7b1f2a3c-4d5e-4f60-8a1b-2c3d4e5f6a7b',
                'event_id' => 1,
                'seat_ids' => [3, 7, 11],
                'buyer_id' => 42,
            ];
        }
    };
};

it('lands on a queue bound to the event type and on no queue bound to another pattern', function () use ($brokerUrl, $connect, $orderPaid): void {
    $url = $brokerUrl();

    $consumer = $connect($url);
    $channel = $consumer->channel();
    $channel->exchange_declare('seatly.events', 'topic', false, true, false);

    [$matching] = $channel->queue_declare('', false, false, true, true);
    [$foreign] = $channel->queue_declare('', false, false, true, true);
    $channel->queue_bind((string) $matching, 'seatly.events', 'order.*');
    $channel->queue_bind((string) $foreign, 'seatly.events', 'event.published');

    config(['messaging.rabbitmq.url' => $url]);
    app()->forgetInstance(EventPublisherInterface::class);
    $publisher = app(EventPublisherInterface::class);

    expect($publisher)->toBeInstanceOf(RabbitMqEventPublisher::class);

    $publisher->publish($orderPaid());

    $delivered = null;

    for ($attempt = 0; $attempt < 50 && $delivered === null; $attempt++) {
        $delivered = $channel->basic_get((string) $matching, true);

        if ($delivered === null) {
            usleep(100_000);
        }
    }

    $wrongQueue = $channel->basic_get((string) $foreign, true);

    $channel->close();
    $consumer->close();

    expect($delivered)->toBeInstanceOf(AMQPMessage::class)
        ->and($wrongQueue)->toBeNull();

    $body = $delivered->getBody();
    $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

    expect($delivered->getRoutingKey())->toBe('order.paid')
        ->and($decoded['event_type'])->toBe($delivered->getRoutingKey())
        ->and($delivered->get('content_type'))->toBe('application/json')
        ->and($delivered->get('delivery_mode'))->toBe(AMQPMessage::DELIVERY_MODE_PERSISTENT)
        ->and($delivered->get('message_id'))->toBe($decoded['event_id'])
        ->and($decoded['event_id'])->not->toBe((string) $decoded['payload']['event_id']);

    $validator = new Validator;
    $validator->resolver()?->registerPrefix('https://schemas.seatly.dev/events/', base_path('docs/events'));

    $result = $validator->validate(
        json_decode($body, false, 512, JSON_THROW_ON_ERROR),
        'https://schemas.seatly.dev/events/order.paid.json',
    );

    expect($result->isValid())->toBeTrue();
});
