<?php

declare(strict_types=1);

use App\Domain\Event\Events\EventPublished;
use App\Domain\Shared\Contracts\DomainEvent;
use App\Infrastructure\Messaging\EnvelopeSchemaValidator;
use App\Infrastructure\Messaging\InvalidEventEnvelopeException;
use App\Infrastructure\Messaging\RabbitMqEventPublisher;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use Psr\Log\AbstractLogger;

$schemaDirectory = dirname(__DIR__, 2).'/docs/events';

$spyLogger = function (): AbstractLogger {
    return new class extends AbstractLogger
    {
        /** @var list<array{level: mixed, message: string|Stringable, context: array<mixed>}> */
        public array $records = [];

        public function log($level, string|Stringable $message, array $context = []): void
        {
            $this->records[] = ['level' => $level, 'message' => $message, 'context' => $context];
        }
    };
};

$badEvent = function (): DomainEvent {
    return new class implements DomainEvent
    {
        public function type(): string
        {
            return EventPublished::TYPE;
        }

        /**
         * @return array<string, mixed>
         */
        public function payload(): array
        {
            return ['event_id' => 1, 'seat_ids' => [1], 'sneaky' => true];
        }
    };
};

$fakeBroker = function (?Closure $onPublish = null): object {
    $channel = new class($onPublish)
    {
        public int $closes = 0;

        /** @var list<array{exchange: string, type: string, passive: bool, durable: bool, auto_delete: bool}> */
        public array $declared = [];

        /** @var list<array{message: AMQPMessage, exchange: string, routing_key: string}> */
        public array $published = [];

        public function __construct(private readonly ?Closure $onPublish) {}

        public function exchange_declare(string $exchange, string $type, bool $passive, bool $durable, bool $autoDelete): void
        {
            $this->declared[] = [
                'exchange' => $exchange,
                'type' => $type,
                'passive' => $passive,
                'durable' => $durable,
                'auto_delete' => $autoDelete,
            ];
        }

        public function basic_publish(AMQPMessage $message, string $exchange, string $routingKey): void
        {
            if ($this->onPublish instanceof Closure) {
                ($this->onPublish)();
            }

            $this->published[] = ['message' => $message, 'exchange' => $exchange, 'routing_key' => $routingKey];
        }

        public function close(): void
        {
            $this->closes++;
        }
    };

    return new class($channel)
    {
        public int $opens = 0;

        public int $closes = 0;

        public function __construct(public readonly object $channel) {}

        public function open(): self
        {
            $this->opens++;

            return $this;
        }

        public function channel(): object
        {
            return $this->channel;
        }

        public function close(): void
        {
            $this->closes++;
        }
    };
};

it('never opens a broker connection when the message fails its schema', function () use ($schemaDirectory, $spyLogger, $badEvent): void {
    $connects = 0;

    $publisher = new RabbitMqEventPublisher(
        function () use (&$connects): AMQPStreamConnection {
            $connects++;

            throw new RuntimeException('the connection factory must never be reached');
        },
        'seatly.events',
        new EnvelopeSchemaValidator($schemaDirectory),
        $spyLogger(),
    );

    expect(fn (): mixed => $publisher->publish($badEvent()))
        ->toThrow(InvalidEventEnvelopeException::class)
        ->and($connects)->toBe(0);
});

it('lets a schema failure escape while swallowing a broker failure, because they are different kinds of wrong', function () use ($schemaDirectory, $spyLogger, $badEvent): void {
    $logger = $spyLogger();

    $publisher = new RabbitMqEventPublisher(
        static fn (): AMQPStreamConnection => throw new RuntimeException('broker down'),
        'seatly.events',
        new EnvelopeSchemaValidator($schemaDirectory),
        $logger,
    );

    expect(fn (): mixed => $publisher->publish(new EventPublished(1, [1])))
        ->not->toThrow(RuntimeException::class)
        ->and(fn (): mixed => $publisher->publish($badEvent()))
        ->toThrow(InvalidEventEnvelopeException::class)
        ->and($logger->records)->toHaveCount(1);
});

it('returns normally and logs one error when the broker cannot be reached', function () use ($schemaDirectory, $spyLogger): void {
    $logger = $spyLogger();

    $publisher = new RabbitMqEventPublisher(
        static fn (): AMQPStreamConnection => new AMQPStreamConnection(
            '127.0.0.1', 1, 'seatly', 'seatly', '/', false, 'AMQPLAIN', null, 'en_US', 1.0, 1.0,
        ),
        'seatly.events',
        new EnvelopeSchemaValidator($schemaDirectory),
        $logger,
    );

    $publisher->publish(new EventPublished(9, [1, 2]));

    expect($logger->records)->toHaveCount(1)
        ->and($logger->records[0]['level'])->toBe('error')
        ->and($logger->records[0]['context']['event_type'])->toBe('event.published')
        ->and($logger->records[0]['context']['event_id'])->toMatch('/^[0-9a-f-]{36}$/');
});

it('closes the connection and the channel it opened after a successful publish', function () use ($schemaDirectory, $spyLogger, $fakeBroker): void {
    $logger = $spyLogger();
    $broker = $fakeBroker();

    $publisher = new RabbitMqEventPublisher(
        static fn (): object => $broker->open(),
        'seatly.events',
        new EnvelopeSchemaValidator($schemaDirectory),
        $logger,
    );

    $publisher->publish(new EventPublished(9, [1, 2]));

    $sent = $broker->channel->published[0];
    $decoded = json_decode($sent['message']->getBody(), true, 512, JSON_THROW_ON_ERROR);

    expect($broker->opens)->toBe(1)
        ->and($broker->closes)->toBe(1)
        ->and($broker->channel->closes)->toBe(1)
        ->and($broker->channel->declared)->toBe([[
            'exchange' => 'seatly.events',
            'type' => 'topic',
            'passive' => false,
            'durable' => true,
            'auto_delete' => false,
        ]])
        ->and($broker->channel->published)->toHaveCount(1)
        ->and($sent['exchange'])->toBe('seatly.events')
        ->and($sent['routing_key'])->toBe('event.published')
        ->and($decoded['event_type'])->toBe($sent['routing_key'])
        ->and($sent['message']->get('message_id'))->toBe($decoded['event_id'])
        ->and($logger->records)->toBe([]);
});

it('closes the connection it opened even when the broker fails mid-publish', function () use ($schemaDirectory, $spyLogger, $fakeBroker): void {
    $logger = $spyLogger();
    $broker = $fakeBroker(static fn (): never => throw new RuntimeException('the broker went away mid-publish'));

    $publisher = new RabbitMqEventPublisher(
        static fn (): object => $broker->open(),
        'seatly.events',
        new EnvelopeSchemaValidator($schemaDirectory),
        $logger,
    );

    $publisher->publish(new EventPublished(9, [1, 2]));

    expect($broker->opens)->toBe(1)
        ->and($broker->closes)->toBe(1)
        ->and($broker->channel->closes)->toBe(0)
        ->and($logger->records)->toHaveCount(1)
        ->and($logger->records[0]['level'])->toBe('error');
});

it('lets a programming error out of the send path rather than logging it as a broker outage', function (string $failure) use ($schemaDirectory, $spyLogger): void {
    $logger = $spyLogger();

    $publisher = new RabbitMqEventPublisher(
        static fn (): never => throw new $failure('a defect in our code, not an unreachable broker'),
        'seatly.events',
        new EnvelopeSchemaValidator($schemaDirectory),
        $logger,
    );

    expect(fn (): mixed => $publisher->publish(new EventPublished(1, [1])))
        ->toThrow($failure)
        ->and($logger->records)->toBe([]);
})->with([
    'a logic error' => [LogicException::class],
    'a type error' => [TypeError::class],
    'a value error' => [ValueError::class],
]);
