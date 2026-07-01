<?php

declare(strict_types=1);

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
    fwrite(STDERR, 'publish_request.php is pointed at '.json_encode([config('database.default'), DB::connection()->getDatabaseName()]).', not pgsql/seatly_test.'.PHP_EOL);
    exit(2);
}

[$eventId, $token] = [(string) ($argv[1] ?? ''), (string) ($argv[2] ?? '')];

$kernel = $app->make(HttpKernel::class);

$response = $kernel->handle(Request::create(
    '/api/v1/organizer/events/'.$eventId.'/publish',
    'POST',
    server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'HTTP_ACCEPT' => 'application/json'],
));

echo json_encode([
    'status' => $response->getStatusCode(),
    'body' => $response->getContent(),
], JSON_THROW_ON_ERROR);

$kernel->terminate(Request::createFromGlobals(), $response);
