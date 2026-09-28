<?php

declare(strict_types=1);

use App\Domain\Order\Events\OrderPaymentFailed;
use App\Domain\Shared\Contracts\DomainEvent;
use App\Infrastructure\Messaging\EnvelopeSchemaValidator;
use App\Infrastructure\Messaging\InvalidEventEnvelopeException;
use App\Infrastructure\Messaging\LoggingEventPublisher;
use Illuminate\Support\Str;
use Psr\Log\AbstractLogger;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

const LOGGING_PUBLISHER_ENVELOPE_ID = '5b0e7c2a-3f41-4d8e-9a6b-2c1d0e9f8a7b';
const LOGGING_PUBLISHER_ORDER_ID = '0f9e8d7c-6b5a-4c3d-8e2f-1a0b9c8d7e6f';
const LOGGING_PUBLISHER_SESSION_ID = 'd4c3b2a1-9f8e-4d7c-8b6a-5f4e3d2c1b0a';

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

beforeEach(function (): void {
    Str::createUuidsUsing(static fn (): UuidInterface => Uuid::fromString(LOGGING_PUBLISHER_ENVELOPE_ID));
});

afterEach(function (): void {
    Str::createUuidsNormally();
});

it('logs one info record carrying only the event type and the envelope id', function () use ($schemaDirectory, $spyLogger): void {
    $logger = $spyLogger();
    $publisher = new LoggingEventPublisher($logger, new EnvelopeSchemaValidator($schemaDirectory));

    $publisher->publish(new OrderPaymentFailed(LOGGING_PUBLISHER_ORDER_ID, 7, [1, 2], LOGGING_PUBLISHER_SESSION_ID));

    expect($logger->records)->toHaveCount(1)
        ->and($logger->records[0]['level'])->toBe('info')
        ->and($logger->records[0]['message'])->toBe('order.payment_failed')
        ->and($logger->records[0]['context'])->toBe([
            'event_type' => 'order.payment_failed',
            'event_id' => LOGGING_PUBLISHER_ENVELOPE_ID,
        ])
        ->and($logger->records[0]['context']['event_id'])->not->toBe(7);
});

it('never writes the payload or the hold session id to the log', function () use ($schemaDirectory, $spyLogger): void {
    $logger = $spyLogger();
    $publisher = new LoggingEventPublisher($logger, new EnvelopeSchemaValidator($schemaDirectory));

    $publisher->publish(new OrderPaymentFailed(LOGGING_PUBLISHER_ORDER_ID, 7, [1, 2], LOGGING_PUBLISHER_SESSION_ID));

    expect($logger->records[0]['context'])->not->toHaveKey('payload')
        ->and(json_encode($logger->records, JSON_THROW_ON_ERROR))->not->toContain(LOGGING_PUBLISHER_SESSION_ID);
});

it('refuses an envelope that fails its schema and logs nothing', function () use ($schemaDirectory, $spyLogger): void {
    $logger = $spyLogger();
    $publisher = new LoggingEventPublisher($logger, new EnvelopeSchemaValidator($schemaDirectory));

    $event = new class implements DomainEvent
    {
        public function type(): string
        {
            return OrderPaymentFailed::TYPE;
        }

        /**
         * @return array<string, mixed>
         */
        public function payload(): array
        {
            return [
                'order_id' => LOGGING_PUBLISHER_ORDER_ID,
                'event_id' => 7,
                'seat_ids' => [1, 2],
                'session_id' => LOGGING_PUBLISHER_SESSION_ID,
                'sneaky' => true,
            ];
        }
    };

    expect(fn (): mixed => $publisher->publish($event))
        ->toThrow(InvalidEventEnvelopeException::class)
        ->and($logger->records)->toBe([]);
});
