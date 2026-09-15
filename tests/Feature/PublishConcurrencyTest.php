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

$seed = function (int $rows, int $seats): array {
    $organizer = User::factory()->create(['role' => UserRole::Organizer]);

    $template = [];

    for ($row = 1; $row <= $rows; $row++) {
        for ($number = 1; $number <= $seats; $number++) {
            $template[] = ['row' => $row, 'number' => $number, 'x' => $number * 40, 'y' => $row * 40, 'price_cents' => 5000];
        }
    }

    $venueId = (int) DB::table('venues')->insertGetId([
        'name' => 'Riverside Arena',
        'address' => '14 Quay Street',
        'city' => 'Rotterdam',
        'seat_map_template' => json_encode(['sections' => [['name' => 'A', 'seats' => $template]]], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $eventId = (int) DB::table('events')->insertGetId([
        'organizer_id' => $organizer->id,
        'venue_id' => $venueId,
        'title' => 'Contended Gala',
        'description' => null,
        'starts_at' => '2027-03-04T18:00:00Z',
        'status' => 'draft',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [
        'event_id' => $eventId,
        'token' => $organizer->createToken('api')->plainTextToken,
        'seats' => $rows * $seats,
    ];
};

it('resolves two genuinely parallel publishes of the same event to one winner and one set of seats', function () use ($freshSchema, $seed, $childEnvironment, $blockedBackends): void {
    $freshSchema();

    try {
        $world = $seed(4, 5);

        DB::beginTransaction();
        DB::select('select id from events where id = ? for update', [$world['event_id']]);

        $processes = [];

        foreach ([1, 2] as $ignored) {
            $process = new Process(
                [PHP_BINARY, base_path('tests/Support/publish_request.php'), (string) $world['event_id'], $world['token']],
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

            $this->fail('Only '.$blocked.' of 2 publish requests were waiting on the event row lock, so the two never contended.');
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

        $loser = $outcomes[array_search(409, array_column($outcomes, 'status'), true)];

        expect($statuses)->toBe([200, 409])
            ->and(json_decode((string) $loser['body'], true, 512, JSON_THROW_ON_ERROR))
            ->toBe(['error' => [
                'code' => 'INVALID_STATE_TRANSITION',
                'message' => 'Only a draft event can be edited or published.',
                'details' => ['status' => 'published'],
            ]])
            ->and(DB::table('event_seats')->where('event_id', $world['event_id'])->count())->toBe($world['seats'])
            ->and(DB::table('events')->where('id', $world['event_id'])->value('status'))->toBe('published');
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        $freshSchema();
    }
});
