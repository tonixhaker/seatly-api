<?php

declare(strict_types=1);

use App\Domain\Event\Enums\EventStatus;
use App\Domain\Event\Enums\SeatStatus;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Ticket\Enums\TicketStatus;
use App\Domain\User\Enums\UserRole;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

$insertUser = function (string $role = 'buyer'): int {
    return (int) DB::table('users')->insertGetId([
        'name' => 'Seed Person',
        'email' => Str::uuid()->toString().'@example.com',
        'password' => 'not-a-real-hash',
        'role' => $role,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
};

$insertVenue = function (): int {
    return (int) DB::table('venues')->insertGetId([
        'name' => 'Seed Hall',
        'address' => '1 Seed Street',
        'city' => 'Seedville',
        'seat_map_template' => json_encode(['sections' => []], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
};

$insertEvent = function (int $organizerId, int $venueId, string $status = 'draft'): int {
    return (int) DB::table('events')->insertGetId([
        'organizer_id' => $organizerId,
        'venue_id' => $venueId,
        'title' => 'Seed Event',
        'description' => null,
        'starts_at' => now()->addDay(),
        'status' => $status,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
};

$insertSeat = function (int $eventId, int $number = 1, string $status = 'free'): int {
    return (int) DB::table('event_seats')->insertGetId([
        'event_id' => $eventId,
        'section' => 'Stalls',
        'row' => 1,
        'number' => $number,
        'x' => 10,
        'y' => 20,
        'price_cents' => 5000,
        'currency' => 'EUR',
        'status' => $status,
    ]);
};

$insertOrder = function (int $buyerId, int $eventId, string $key, string $status = 'pending'): string {
    $id = Str::uuid()->toString();

    DB::table('orders')->insert([
        'id' => $id,
        'buyer_id' => $buyerId,
        'event_id' => $eventId,
        'status' => $status,
        'total_cents' => 5000,
        'currency' => 'EUR',
        'idempotency_key' => $key,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
};

$scaffold = function () use ($insertUser, $insertVenue, $insertEvent, $insertSeat, $insertOrder): array {
    $organizerId = $insertUser('organizer');
    $buyerId = $insertUser('buyer');
    $venueId = $insertVenue();
    $eventId = $insertEvent($organizerId, $venueId);
    $seatId = $insertSeat($eventId);
    $orderId = $insertOrder($buyerId, $eventId, 'scaffold-key');

    return [
        'organizer_id' => $organizerId,
        'buyer_id' => $buyerId,
        'venue_id' => $venueId,
        'event_id' => $eventId,
        'seat_id' => $seatId,
        'order_id' => $orderId,
    ];
};

it('rejects users.role = admin at the database level', function () use ($insertUser): void {
    expect(fn () => $insertUser('admin'))
        ->toThrow(QueryException::class, 'users_role_check');
});

it('accepts both real roles at the database level, so the rejection above is not vacuous', function () use ($insertUser): void {
    expect($insertUser('buyer'))->toBeGreaterThan(0)
        ->and($insertUser('organizer'))->toBeGreaterThan(0);
});

it('rejects a duplicate email at the database level', function () use ($scaffold): void {
    $scaffold();
    $email = DB::table('users')->value('email');

    expect(fn () => DB::table('users')->insert([
        'name' => 'Clash',
        'email' => $email,
        'password' => 'not-a-real-hash',
        'role' => 'buyer',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class, 'users_email_unique');
});

it('rejects a second event_seats row with the same event, section, row and number', function () use ($scaffold, $insertSeat): void {
    $ids = $scaffold();

    expect(fn () => $insertSeat($ids['event_id'], 1))
        ->toThrow(QueryException::class, 'event_seats_event_id_section_row_number_unique');
});

it('accepts a second event_seats row differing only in number, so the rejection above is not vacuous', function () use ($scaffold, $insertSeat): void {
    $ids = $scaffold();

    expect($insertSeat($ids['event_id'], 2))->toBeGreaterThan(0);
});

it('rejects a second orders row with an existing idempotency_key', function () use ($scaffold, $insertOrder): void {
    $ids = $scaffold();

    expect(fn () => $insertOrder($ids['buyer_id'], $ids['event_id'], 'scaffold-key'))
        ->toThrow(QueryException::class, 'orders_idempotency_key_unique');
});

it('rejects a duplicate tickets.qr_code', function () use ($scaffold): void {
    $ids = $scaffold();

    DB::table('tickets')->insert([
        'order_id' => $ids['order_id'],
        'event_seat_id' => $ids['seat_id'],
        'qr_code' => 'A1B2C3D4E5F6',
        'status' => 'issued',
        'checked_in_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => DB::table('tickets')->insert([
        'order_id' => $ids['order_id'],
        'event_seat_id' => $ids['seat_id'],
        'qr_code' => 'A1B2C3D4E5F6',
        'status' => 'issued',
        'checked_in_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class, 'tickets_qr_code_unique');
});

it('rejects a status outside the enum at the database level', function (string $table, string $constraint) use ($scaffold): void {
    $ids = $scaffold();

    $rows = [
        'events' => [
            'organizer_id' => $ids['organizer_id'],
            'venue_id' => $ids['venue_id'],
            'title' => 'Bad status',
            'description' => null,
            'starts_at' => now()->addDay(),
            'status' => 'nonsense',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        'event_seats' => [
            'event_id' => $ids['event_id'],
            'section' => 'Stalls',
            'row' => 9,
            'number' => 9,
            'x' => 1,
            'y' => 2,
            'price_cents' => 100,
            'currency' => 'EUR',
            'status' => 'nonsense',
        ],
        'orders' => [
            'buyer_id' => $ids['buyer_id'],
            'event_id' => $ids['event_id'],
            'status' => 'nonsense',
            'total_cents' => 100,
            'currency' => 'EUR',
            'idempotency_key' => 'bad-status-key',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        'tickets' => [
            'order_id' => $ids['order_id'],
            'event_seat_id' => $ids['seat_id'],
            'qr_code' => 'BADSTATUS01',
            'status' => 'nonsense',
            'checked_in_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ];

    expect(fn () => DB::table($table)->insert($rows[$table]))
        ->toThrow(QueryException::class, $constraint);
})->with([
    'events.status' => ['events', 'events_status_check'],
    'event_seats.status' => ['event_seats', 'event_seats_status_check'],
    'orders.status' => ['orders', 'orders_status_check'],
    'tickets.status' => ['tickets', 'tickets_status_check'],
]);

it('defaults the primary key to a generated uuid', function (string $table) use ($scaffold): void {
    $ids = $scaffold();

    $rows = [
        'orders' => [
            'buyer_id' => $ids['buyer_id'],
            'event_id' => $ids['event_id'],
            'status' => 'pending',
            'total_cents' => 100,
            'currency' => 'EUR',
            'idempotency_key' => 'uuid-default-key',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        'tickets' => [
            'order_id' => $ids['order_id'],
            'event_seat_id' => $ids['seat_id'],
            'qr_code' => 'UUIDDEFAULT1',
            'status' => 'issued',
            'checked_in_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ];

    DB::table($table)->insert($rows[$table]);

    $generated = DB::table($table)->orderByDesc('id')->value('id');

    expect($generated)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/');
})->with(['orders', 'tickets']);

it('stores venues.seat_map_template as jsonb, which sqlite cannot represent', function (): void {
    $type = DB::table('information_schema.columns')
        ->where('table_schema', 'public')
        ->where('table_name', 'venues')
        ->where('column_name', 'seat_map_template')
        ->value('data_type');

    expect($type)->toBe('jsonb');
});

it('refuses to delete a row another table still references', function (string $table, string $idKey) use ($scaffold): void {
    $ids = $scaffold();

    DB::table('order_items')->insert([
        'order_id' => $ids['order_id'],
        'event_seat_id' => $ids['seat_id'],
        'price_cents' => 5000,
    ]);

    expect(fn () => DB::table($table)->where('id', $ids[$idKey])->delete())
        ->toThrow(QueryException::class, 'violates foreign key constraint');
})->with([
    'users referenced by events' => ['users', 'organizer_id'],
    'venues referenced by events' => ['venues', 'venue_id'],
    'events referenced by event_seats' => ['events', 'event_id'],
    'orders referenced by order_items' => ['orders', 'order_id'],
    'event_seats referenced by order_items' => ['event_seats', 'seat_id'],
]);

it('declares a check constraint whose literals match the PHP enum exactly', function (string $constraint, string $enum): void {
    $definition = (string) DB::selectOne(
        'select pg_get_constraintdef(oid) as def from pg_constraint where conname = ? and connamespace = ?::regnamespace',
        [$constraint, 'public']
    )?->def;

    preg_match_all("/'([^']+)'::character varying/", $definition, $matches);

    expect($matches[1])->toEqualCanonicalizing(array_column($enum::cases(), 'value'));
})->with([
    'users.role' => ['users_role_check', UserRole::class],
    'events.status' => ['events_status_check', EventStatus::class],
    'event_seats.status' => ['event_seats_status_check', SeatStatus::class],
    'orders.status' => ['orders_status_check', OrderStatus::class],
    'tickets.status' => ['tickets_status_check', TicketStatus::class],
]);

it('indexes the leading column of every foreign key', function (): void {
    $unindexed = DB::select("
        select c.conrelid::regclass::text as tbl, a.attname as col
        from pg_constraint c
        join unnest(c.conkey) as k(attnum) on true
        join pg_attribute a on a.attrelid = c.conrelid and a.attnum = k.attnum
        where c.contype = 'f' and c.connamespace = 'public'::regnamespace
        and not exists (
            select 1 from pg_index i
            where i.indrelid = c.conrelid and i.indkey[0] = k.attnum
        )
    ");

    expect($unindexed)->toBe([]);
});

it('declares the column type, nullability and length the generated client contract depends on', function (string $table, string $column, string $type, string $nullable, ?int $length): void {
    $col = DB::table('information_schema.columns')
        ->where('table_schema', 'public')
        ->where('table_name', $table)
        ->where('column_name', $column)
        ->first(['data_type', 'is_nullable', 'character_maximum_length']);

    expect($col)->not->toBeNull()
        ->and($col->data_type)->toBe($type)
        ->and($col->is_nullable)->toBe($nullable)
        ->and($col->character_maximum_length === null ? null : (int) $col->character_maximum_length)->toBe($length);
})->with([
    'event_seats.price_cents is integer minor units' => ['event_seats', 'price_cents', 'integer', 'NO', null],
    'orders.total_cents is integer minor units' => ['orders', 'total_cents', 'integer', 'NO', null],
    'order_items.price_cents is integer minor units' => ['order_items', 'price_cents', 'integer', 'NO', null],
    'event_seats.x is an integer svg coordinate' => ['event_seats', 'x', 'integer', 'NO', null],
    'event_seats.y is an integer svg coordinate' => ['event_seats', 'y', 'integer', 'NO', null],
    'event_seats.currency is 3 characters' => ['event_seats', 'currency', 'character varying', 'NO', 3],
    'orders.currency is 3 characters' => ['orders', 'currency', 'character varying', 'NO', 3],
    'tickets.qr_code is 12 characters' => ['tickets', 'qr_code', 'character varying', 'NO', 12],
    'orders.id is a uuid' => ['orders', 'id', 'uuid', 'NO', null],
    'tickets.id is a uuid' => ['tickets', 'id', 'uuid', 'NO', null],
    'events.starts_at is required' => ['events', 'starts_at', 'timestamp without time zone', 'NO', null],
    'events.description is nullable' => ['events', 'description', 'text', 'YES', null],
    'tickets.checked_in_at is nullable' => ['tickets', 'checked_in_at', 'timestamp without time zone', 'YES', null],
]);
