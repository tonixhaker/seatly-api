<?php

declare(strict_types=1);

use App\Domain\Order\Contracts\HoldsValidatorInterface;
use App\Domain\Order\Contracts\PaymentGatewayInterface;
use App\Domain\Order\DTO\PaymentResult;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

/** @var Application $app */
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();

if (config('database.default') !== 'pgsql' || DB::connection()->getDatabaseName() !== 'seatly_test') {
    fwrite(STDERR, 'place_order_request.php is pointed at '.json_encode([config('database.default'), DB::connection()->getDatabaseName()]).', not pgsql/seatly_test.'.PHP_EOL);
    exit(2);
}

[$eventId, $seatIds, $token, $key] = [
    (int) ($argv[1] ?? 0),
    array_values(array_map(intval(...), explode(',', (string) ($argv[2] ?? '')))),
    (string) ($argv[3] ?? ''),
    (string) ($argv[4] ?? ''),
];

$app->instance(HoldsValidatorInterface::class, new class implements HoldsValidatorInterface
{
    public function missingSeats(int $eventId, array $seatIds, string $sessionId): array
    {
        return [];
    }
});

$gateway = new class implements PaymentGatewayInterface
{
    public int $charges = 0;

    public function charge(int $amountCents, string $currency): PaymentResult
    {
        $this->charges++;

        return PaymentResult::approved($amountCents, $currency);
    }
};

$app->instance(PaymentGatewayInterface::class, $gateway);

$kernel = $app->make(HttpKernel::class);

$response = $kernel->handle(Request::create(
    '/api/v1/orders',
    'POST',
    server: [
        'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        'HTTP_ACCEPT' => 'application/json',
        'CONTENT_TYPE' => 'application/json',
    ],
    content: json_encode([
        'event_id' => $eventId,
        'seat_ids' => $seatIds,
        'session_id' => '11111111-2222-4333-8444-555555555555',
        'idempotency_key' => $key,
    ], JSON_THROW_ON_ERROR),
));

echo json_encode([
    'status' => $response->getStatusCode(),
    'body' => $response->getContent(),
    'charges' => $gateway->charges,
], JSON_THROW_ON_ERROR);

$kernel->terminate(Request::createFromGlobals(), $response);
