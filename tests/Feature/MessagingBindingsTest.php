<?php

declare(strict_types=1);

use App\Domain\Event\Events\EventPublished;
use App\Domain\Order\Contracts\PaymentGatewayInterface;
use App\Domain\Shared\Contracts\DomainEvent;
use App\Domain\Shared\Contracts\EventPublisherInterface;
use App\Infrastructure\Messaging\EnvelopeSchemaValidator;
use App\Infrastructure\Messaging\InvalidEventEnvelopeException;
use App\Infrastructure\Messaging\LoggingEventPublisher;
use App\Infrastructure\Messaging\RabbitMqEventPublisher;
use App\Infrastructure\Payments\FakePaymentGateway;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;

$rebind = function (string $url): EventPublisherInterface {
    config(['messaging.rabbitmq.url' => $url]);
    app()->forgetInstance(EventPublisherInterface::class);

    return app(EventPublisherInterface::class);
};

it('publishes to the broker when a url is configured and logs when none is', function () use ($rebind): void {
    expect($rebind('amqp://seatly:seatly@rabbitmq:5672'))->toBeInstanceOf(RabbitMqEventPublisher::class)
        ->and($rebind(''))->toBeInstanceOf(LoggingEventPublisher::class);
});

it('keeps the test suite on the logging publisher, so no test talks to a real broker by accident', function (): void {
    expect(config('messaging.rabbitmq.url'))->toBe('')
        ->and(app(EventPublisherInterface::class))->toBeInstanceOf(LoggingEventPublisher::class);
});

it('points the schema validator at the committed contract directory', function (): void {
    expect(config('messaging.schema_path'))->toBe(base_path('docs/events'))
        ->and(is_file(base_path('docs/events/envelope.json')))->toBeTrue()
        ->and(app(EnvelopeSchemaValidator::class))->toBeInstanceOf(EnvelopeSchemaValidator::class);
});

it('names the exchange the realtime consumer binds to', function (): void {
    expect(config('messaging.rabbitmq.exchange'))->toBe('seatly.events');
});

it('resolves the payment gateway from the container', function (): void {
    expect(app(PaymentGatewayInterface::class))->toBeInstanceOf(FakePaymentGateway::class);
});

it('configures the fake gateway with the delay range and decline rate the specification names', function (): void {
    expect(config('payments.fake'))->toBe([
        'delay_min_ms' => 500,
        'delay_max_ms' => 2000,
        'decline_rate' => 0.15,
    ]);
});

it('feeds that configuration into the gateway rather than hardcoding a delay', function (): void {
    config(['payments.fake.delay_min_ms' => 30, 'payments.fake.delay_max_ms' => 30]);
    app()->forgetInstance(PaymentGatewayInterface::class);

    $before = hrtime(true);
    app(PaymentGatewayInterface::class)->charge(5000, 'EUR');
    $elapsedMs = (hrtime(true) - $before) / 1_000_000;

    expect($elapsedMs)->toBeGreaterThan(28.0)
        ->and($elapsedMs)->toBeLessThan(400.0);
});

it('logs only the event type and envelope id, never the payload', function (): void {
    $logger = new class extends AbstractLogger
    {
        /** @var list<array{message: string|Stringable, context: array<mixed>}> */
        public array $records = [];

        public function log($level, string|Stringable $message, array $context = []): void
        {
            $this->records[] = ['message' => $message, 'context' => $context];
        }
    };

    $publisher = new LoggingEventPublisher($logger, app(EnvelopeSchemaValidator::class));

    $publisher->publish(new EventPublished(7, [1, 2, 3]));

    expect($logger->records)->toHaveCount(1)
        ->and($logger->records[0]['message'])->toBe('event.published')
        ->and(array_keys($logger->records[0]['context']))->toBe(['event_type', 'event_id'])
        ->and($logger->records[0]['context']['event_type'])->toBe('event.published')
        ->and($logger->records[0]['context']['event_id'])->toBeString()->toBeUuid();
});

it('refuses to log a message its own schema rejects, so the stub cannot drift from the contract', function (): void {
    $publisher = new LoggingEventPublisher(new NullLogger, app(EnvelopeSchemaValidator::class));

    $bad = new class implements DomainEvent
    {
        public function type(): string
        {
            return 'event.published';
        }

        /**
         * @return array<string, mixed>
         */
        public function payload(): array
        {
            return ['event_id' => 7, 'seat_ids' => [1], 'sneaky' => true];
        }
    };

    expect(fn (): mixed => $publisher->publish($bad))
        ->toThrow(InvalidEventEnvelopeException::class);
});
