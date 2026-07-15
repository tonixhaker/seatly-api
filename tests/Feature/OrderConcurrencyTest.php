<?php

declare(strict_types=1);

use App\Domain\User\Enums\UserRole;
use App\Domain\User\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

$freshSchema = function (): void {
    Artisan::call('migrate:fresh', ['--force' => true]);
};

$childEnvironment = function (): array {
    return [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => 'pgsql',
        'DB_DATABASE' => 'seatly_test',
        'DB_HOST' => (string) config('database.connections.pgsql.host'),
        'DB_PORT' => (string) config('database.connections.pgsql.port'),
        'DB_USERNAME' => (string) config('database.connections.pgsql.username'),
        'DB_PASSWORD' => (string) config('database.connections.pgsql.password'),
    ];
};

$blockedBackends = function (): int {
    DB::select('select pg_stat_clear_snapshot()');

    $row = DB::selectOne(
        'select count(*) as blocked from pg_stat_activity where datname = current_database() and cardinality(pg_blocking_pids(pid)) > 0'
    );

    return (int) ($row->blocked ?? 0);
};

$seed = function (): array {
    $organizer = User::factory()->create(['role' => UserRole::Organizer]);
    $buyer = User::factory()->create(['role' => UserRole::Buyer]);

    $venueId = (int) DB::table('venues')->insertGetId([
        'name' => 'Riverside Arena',
        'address' => '14 Quay Street',
        'city' => 'Rotterdam',
        'seat_map_template' => json_encode(['sections' => []], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $eventId = (int) DB::table('events')->insertGetId([
        'organizer_id' => $organizer->id,
        'venue_id' => $venueId,
        'title' => 'Contended Gala',
        'description' => null,
        'starts_at' => '2027-03-04T18:00:00Z',
        'status' => 'published',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('event_seats')->insert([
        'event_id' => $eventId,
        'section' => 'A',
        'row' => 1,
        'number' => 1,
        'x' => 40,
        'y' => 40,
        'price_cents' => 7300,
        'currency' => 'EUR',
        'status' => 'free',
    ]);

    return [
        'event_id' => $eventId,
        'seat_id' => (int) DB::table('event_seats')->where('event_id', $eventId)->value('id'),
        'token' => $buyer->createToken('api')->plainTextToken,
    ];
};

it('resolves two genuinely parallel purchases of the same seat to one sale and one rejection', function () use ($freshSchema, $seed, $childEnvironment, $blockedBackends): void {
    $freshSchema();

    try {
        $world = $seed();

        DB::beginTransaction();
        DB::select('select id from event_seats where id = ? for update', [$world['seat_id']]);

        $processes = [];

        foreach (['contender-a', 'contender-b'] as $key) {
            $process = new Process(
                [
                    PHP_BINARY,
                    base_path('tests/Support/place_order_request.php'),
                    (string) $world['event_id'],
                    (string) $world['seat_id'],
                    $world['token'],
                    $key,
                ],
                base_path(),
                $childEnvironment(),
            );
            $process->setTimeout(60);
            $process->start();

            $processes[] = $process;
        }

        $deadline = microtime(true) + 20;
        $blocked = 0;

        while (microtime(true) < $deadline) {
            $blocked = $blockedBackends();

            if ($blocked === 2) {
                break;
            }

            usleep(50_000);
        }

        if ($blocked !== 2) {
            DB::rollBack();

            foreach ($processes as $process) {
                $process->stop();
            }

            $this->fail('Only '.$blocked.' of 2 purchases were waiting on the seat row lock, so the two never contended.');
        }

        DB::rollBack();

        $outcomes = [];

        foreach ($processes as $process) {
            $process->wait();

            expect($process->getExitCode())->toBe(0, $process->getErrorOutput());

            $decoded = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);
            $outcomes[] = is_array($decoded) ? $decoded : [];
        }

        $statuses = array_column($outcomes, 'status');
        sort($statuses);

        $loser = $outcomes[array_search(422, array_column($outcomes, 'status'), true)];
        $winner = $outcomes[array_search(201, array_column($outcomes, 'status'), true)];

        expect($statuses)->toBe([201, 422])
            ->and(json_decode((string) $loser['body'], true, 512, JSON_THROW_ON_ERROR))
            ->toBe(['error' => [
                'code' => 'SEATS_NOT_HELD',
                'message' => 'The session no longer holds every requested seat.',
                'details' => ['seats' => [$world['seat_id']]],
            ]])
            ->and($loser['charges'])->toBe(0)
            ->and($winner['charges'])->toBe(1)
            ->and(DB::table('orders')->count())->toBe(1)
            ->and(DB::table('orders')->value('status'))->toBe('paid')
            ->and(DB::table('tickets')->count())->toBe(1)
            ->and(DB::table('event_seats')->where('id', $world['seat_id'])->value('status'))->toBe('sold');
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        $freshSchema();
    }
});
