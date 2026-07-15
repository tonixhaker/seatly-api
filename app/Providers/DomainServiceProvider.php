<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Event\Repositories\EventRepositoryInterface;
use App\Domain\Order\Contracts\HoldsValidatorInterface;
use App\Domain\Order\Contracts\PaymentGatewayInterface;
use App\Domain\Order\Repositories\OrderRepositoryInterface;
use App\Domain\Shared\Contracts\EventPublisherInterface;
use App\Domain\User\Repositories\UserRepositoryInterface;
use App\Domain\Venue\Repositories\VenueRepositoryInterface;
use App\Infrastructure\Messaging\EnvelopeSchemaValidator;
use App\Infrastructure\Messaging\LoggingEventPublisher;
use App\Infrastructure\Messaging\RabbitMqEventPublisher;
use App\Infrastructure\Payments\FakePaymentGateway;
use App\Infrastructure\Persistence\EloquentEventRepository;
use App\Infrastructure\Persistence\EloquentOrderRepository;
use App\Infrastructure\Persistence\EloquentUserRepository;
use App\Infrastructure\Persistence\EloquentVenueRepository;
use App\Infrastructure\Realtime\HttpHoldsValidator;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use Psr\Log\LoggerInterface;
use Random\Randomizer;

final class DomainServiceProvider extends ServiceProvider
{
    private const CONNECTION_TIMEOUT_SECONDS = 2.0;

    public function register(): void
    {
        $this->app->bind(EventRepositoryInterface::class, EloquentEventRepository::class);
        $this->app->bind(VenueRepositoryInterface::class, EloquentVenueRepository::class);
        $this->app->bind(UserRepositoryInterface::class, EloquentUserRepository::class);
        $this->app->bind(OrderRepositoryInterface::class, EloquentOrderRepository::class);

        $this->app->singleton(EnvelopeSchemaValidator::class, static function (): EnvelopeSchemaValidator {
            return new EnvelopeSchemaValidator(Config::string('messaging.schema_path'));
        });

        $this->app->singleton(PaymentGatewayInterface::class, static function (): PaymentGatewayInterface {
            return new FakePaymentGateway(
                new Randomizer,
                Config::integer('payments.fake.delay_min_ms'),
                Config::integer('payments.fake.delay_max_ms'),
                Config::float('payments.fake.decline_rate'),
            );
        });

        $this->app->singleton(HoldsValidatorInterface::class, static function (Application $app): HoldsValidatorInterface {
            return new HttpHoldsValidator(
                $app->make(Factory::class),
                Config::string('realtime.base_url'),
                Config::string('realtime.internal_token'),
                Config::float('realtime.timeout_seconds'),
                $app->make(LoggerInterface::class),
            );
        });

        $this->app->singleton(EventPublisherInterface::class, static function (Application $app): EventPublisherInterface {
            $url = Config::string('messaging.rabbitmq.url');

            $validator = $app->make(EnvelopeSchemaValidator::class);
            $logger = $app->make(LoggerInterface::class);

            if ($url === '') {
                return new LoggingEventPublisher($logger, $validator);
            }

            return new RabbitMqEventPublisher(
                self::connector($url),
                Config::string('messaging.rabbitmq.exchange'),
                $validator,
                $logger,
            );
        });
    }

    private static function connector(string $url): callable
    {
        $parts = parse_url($url);
        $parts = is_array($parts) ? $parts : [];

        $host = is_string($parts['host'] ?? null) ? $parts['host'] : 'localhost';
        $port = is_int($parts['port'] ?? null) ? $parts['port'] : 5672;
        $user = is_string($parts['user'] ?? null) ? rawurldecode($parts['user']) : 'guest';
        $password = is_string($parts['pass'] ?? null) ? rawurldecode($parts['pass']) : 'guest';
        $path = is_string($parts['path'] ?? null) ? ltrim($parts['path'], '/') : '';
        $vhost = $path === '' ? '/' : rawurldecode($path);

        return static fn (): AMQPStreamConnection => new AMQPStreamConnection(
            $host,
            $port,
            $user,
            $password,
            $vhost,
            false,
            'AMQPLAIN',
            null,
            'en_US',
            self::CONNECTION_TIMEOUT_SECONDS,
            self::CONNECTION_TIMEOUT_SECONDS,
        );
    }
}
