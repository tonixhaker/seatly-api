<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

$credentials = [
    'organizer1@seatly.test' => ['organizer1@seatly.test', 'password', 'organizer'],
    'organizer2@seatly.test' => ['organizer2@seatly.test', 'password', 'organizer'],
    'buyer@seatly.test' => ['buyer@seatly.test', 'password', 'buyer'],
];

$login = function (string $email, string $password): string {
    $response = test()->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password])->assertStatus(200);

    return (string) $response->json('token');
};

$publishedSeatPositions = function (int $eventId): array {
    $seats = test()->getJson('/api/v1/events/'.$eventId.'/seats')->assertStatus(200)->json();

    expect($seats)->toBeArray()->not->toBeEmpty();

    return array_map(
        static fn (array $seat): string => $seat['x'].':'.$seat['y'],
        is_array($seats) ? $seats : [],
    );
};

it('logs in with each demo credential', function (string $email, string $password, string $role): void {
    $this->seed();

    $response = $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password])->assertStatus(200);

    expect($response->json('token'))->toBeString()->not->toBe('')
        ->and($response->json('user.email'))->toBe($email)
        ->and($response->json('user.role'))->toBe($role);
})->with($credentials);

it('shows the published demo events in the public catalog and hides the draft', function (): void {
    $this->seed();

    $titles = $this->getJson('/api/v1/events?per_page=50')->assertStatus(200)->json('data.*.title');

    expect($titles)->toHaveCount(2)
        ->toContain('Autumn Symphony', 'Winter Jazz Night')
        ->not->toContain('Spring Gala Preview');
});

it('gives the two demo venues seat maps whose coordinates differ', function () use ($publishedSeatPositions): void {
    $this->seed();

    $ids = DB::table('events')->where('status', 'published')->orderBy('id')->pluck('id')->all();

    expect($ids)->toHaveCount(2);

    $first = $publishedSeatPositions((int) $ids[0]);
    $second = $publishedSeatPositions((int) $ids[1]);

    expect(array_intersect($first, $second))->toBe([]);
});

it('leaves the database unchanged when the seeder runs a second time', function (): void {
    $this->seed();

    $counts = fn (): array => [
        'users' => DB::table('users')->count(),
        'venues' => DB::table('venues')->count(),
        'events' => DB::table('events')->count(),
        'event_seats' => DB::table('event_seats')->count(),
    ];

    $before = $counts();

    $this->seed();

    expect($counts())->toBe($before)
        ->and($before['users'])->toBe(3)
        ->and($before['venues'])->toBe(2)
        ->and($before['events'])->toBe(3);
});

it('lets the demo organizer edit their own seeded draft and hides it from the other organizer', function () use ($credentials, $login): void {
    $this->seed();

    $draft = DB::table('events')->where('status', 'draft')->first();

    expect($draft)->not->toBeNull();

    $payload = [
        'venue_id' => (int) $draft->venue_id,
        'title' => 'Spring Gala Preview',
        'description' => 'Still not announced.',
        'starts_at' => Carbon::now()->addMonths(7)->startOfHour()->toIso8601ZuluString(),
    ];

    $owner = $login($credentials['organizer1@seatly.test'][0], $credentials['organizer1@seatly.test'][1]);
    $other = $login($credentials['organizer2@seatly.test'][0], $credentials['organizer2@seatly.test'][1]);

    $this->app['auth']->forgetGuards();
    $this->withToken($owner)->putJson('/api/v1/organizer/events/'.$draft->id, $payload)->assertStatus(200);

    $this->app['auth']->forgetGuards();
    $this->withToken($other)->putJson('/api/v1/organizer/events/'.$draft->id, $payload)->assertStatus(404);
});

it('keeps the rows it already seeded instead of replacing them', function (): void {
    $this->seed();

    $ids = fn (): array => [
        'users' => DB::table('users')->orderBy('id')->pluck('id')->all(),
        'venues' => DB::table('venues')->orderBy('id')->pluck('id')->all(),
        'events' => DB::table('events')->orderBy('id')->pluck('id')->all(),
        'event_seats' => DB::table('event_seats')->orderBy('id')->pluck('id')->all(),
    ];

    $before = $ids();

    $this->seed();

    expect($ids())->toBe($before)
        ->and($before['event_seats'])->not->toBe([]);
});
