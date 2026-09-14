<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

function healthUnreachableDatabase(): void
{
    DB::shouldReceive('connection')->andThrow(new PDOException('SQLSTATE[08006] could not connect to server: postgres:5432'));
}

it('answers readiness 200 the way compose calls it', function (array $headers): void {
    $this->get('/health', $headers)
        ->assertOk()
        ->assertExactJson(['status' => 'ok', 'checks' => ['database' => 'ok']]);
})->with([
    'no accept header' => [['Accept' => '']],
    'curl default' => [['Accept' => '*/*']],
    'framework default' => [[]],
]);

it('answers readiness 503 and names database when the database is unreachable', function (): void {
    healthUnreachableDatabase();

    $response = $this->get('/health', ['Accept' => '']);

    $response->assertStatus(503)
        ->assertExactJson(['status' => 'error', 'checks' => ['database' => 'error']]);

    expect($response->getContent())->not->toContain('postgres:5432')
        ->and($response->getContent())->not->toContain('SQLSTATE');
});

it('answers liveness 200 with the database removed from the application', function (): void {
    healthUnreachableDatabase();

    $this->get('/health/live', ['Accept' => ''])
        ->assertOk()
        ->assertExactJson(['status' => 'ok']);
});

it('answers readiness 503 when the connection is opened lazily and fails', function (Throwable $failure): void {
    DB::shouldReceive('connection->getPdo')->andThrow($failure);

    $this->get('/health', ['Accept' => ''])
        ->assertStatus(503)
        ->assertExactJson(['status' => 'error', 'checks' => ['database' => 'error']]);
})->with([
    'server unreachable' => [new PDOException('SQLSTATE[08006] could not connect to server: postgres:5432')],
    'connection not configured' => [new InvalidArgumentException('Database connection [pgsql] not configured.')],
]);
