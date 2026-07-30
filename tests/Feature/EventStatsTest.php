<?php

declare(strict_types=1);

use App\Domain\Order\Contracts\PaymentGatewayInterface;
use App\Domain\Order\DTO\PaymentResult;
use App\Domain\User\Enums\UserRole;
use App\Domain\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

$order = static function (int $eventId, string $status, array $items): string {
    $orderId = Str::uuid()->toString();

    DB::table('orders')->insert([
        'id' => $orderId,
        'buyer_id' => User::factory()->create(['role' => UserRole::Buyer])->id,
        'event_id' => $eventId,
        'status' => $status,
        'total_cents' => array_sum($items),
        'currency' => 'EUR',
        'idempotency_key' => 'stats-'.$orderId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    foreach ($items as $seatId => $priceCents) {
        DB::table('order_items')->insert(['order_id' => $orderId, 'event_seat_id' => $seatId, 'price_cents' => $priceCents]);
    }

    return $orderId;
};

$stats = function (int $organizerId, int|string $eventId): TestResponse {
    return $this->actingAs(User::findOrFail($organizerId), 'sanctum')
        ->getJson('/api/v1/organizer/events/'.$eventId.'/stats');
};

it('reports real counts and the paid snapshot revenue of this event only', function () use ($order, $stats): void {
    $ids = $this->seedCatalog();
    $event = $ids['published'][0];
    [$first, $second] = $ids['sold'];
    $order($event, 'paid', [$first => 4200, $second => 3100]);

    $other = $this->seedPurchasable(1);
    $order($other['event'], 'paid', [$other['seats'][0] => 7300]);
    DB::table('event_seats')->where('id', $other['seats'][0])->update(['status' => 'sold']);

    $response = $stats->call($this, $ids['organizer'], $event)->assertOk();

    expect($response->json())->toBe([
        'event_id' => $event,
        'seats_total' => 12,
        'seats_sold' => 2,
        'seats_free' => 10,
        'revenue_cents' => 7300,
        'currency' => 'EUR',
    ]);
});

it('reports two Autumn Symphony seats bought through the order endpoint as sold at their snapshot prices', function () use ($stats): void {
    $ids = $this->seedCatalog();
    $event = $ids['published'][0];
    DB::table('event_seats')->where('event_id', $event)->update(['status' => 'free']);

    $bought = [
        (int) DB::table('event_seats')->where(['event_id' => $event, 'section' => 'A', 'row' => 2, 'number' => 2])->value('id'),
        (int) DB::table('event_seats')->where(['event_id' => $event, 'section' => 'B', 'row' => 2, 'number' => 2])->value('id'),
    ];

    Http::preventStrayRequests();
    Http::fake(fn (): mixed => Http::response(['valid' => true, 'missing' => []], 200));
    $this->instance(PaymentGatewayInterface::class, new class implements PaymentGatewayInterface
    {
        public function charge(int $amountCents, string $currency): PaymentResult
        {
            return PaymentResult::approved($amountCents, $currency);
        }
    });

    $buyer = User::factory()->create(['role' => UserRole::Buyer]);
    $this->actingAs($buyer, 'sanctum')->postJson('/api/v1/orders', [
        'event_id' => $event,
        'seat_ids' => $bought,
        'session_id' => '11111111-2222-4333-8444-555555555555',
        'idempotency_key' => 'stats-purchase',
    ])->assertStatus(201);

    DB::table('event_seats')->whereIn('id', $bought)->update(['price_cents' => 1]);
    $this->app['auth']->forgetGuards();

    $response = $stats->call($this, $ids['organizer'], $event)->assertOk();

    expect($response->json('seats_sold'))->toBe(2)
        ->and($response->json('seats_free'))->toBe($response->json('seats_total') - 2)
        ->and($response->json('seats_total'))->toBe(12)
        ->and($response->json('revenue_cents'))->toBe(5000 + 3500);
});

it('adds nothing to revenue for an order that is not paid', function (string $status) use ($order, $stats): void {
    $ids = $this->seedCatalog();
    $event = $ids['published'][0];
    [$first, $second] = $ids['sold'];
    $order($event, 'paid', [$first => 4200, $second => 3100]);

    $spare = (int) DB::table('event_seats')->where('event_id', $event)->where('status', 'free')->orderBy('id')->value('id');
    $order($event, $status, [$spare => 9999]);

    $response = $stats->call($this, $ids['organizer'], $event)->assertOk();

    expect($response->json('revenue_cents'))->toBe(7300)
        ->and($response->json('seats_sold'))->toBe(2);
})->with(['payment_failed', 'pending', 'cancelled', 'expired']);

it('reports every counter as zero and EUR for a draft that has no seats', function () use ($stats): void {
    $ids = $this->seedCatalog();

    $response = $stats->call($this, $ids['organizer'], $ids['draft'])->assertOk();

    expect($response->json())->toBe([
        'event_id' => $ids['draft'],
        'seats_total' => 0,
        'seats_sold' => 0,
        'seats_free' => 0,
        'revenue_cents' => 0,
        'currency' => 'EUR',
    ]);
});

it('reports the currency the seats were priced in', function () use ($stats): void {
    $world = $this->seedPurchasable(2, 1, 7300, 'USD');
    $organizerId = (int) DB::table('events')->where('id', $world['event'])->value('organizer_id');

    $response = $stats->call($this, $organizerId, $world['event'])->assertOk();

    expect($response->json('currency'))->toBe('USD')
        ->and($response->json('seats_total'))->toBe(3);
});

it('gives a stranger organizer, an unknown id and an oversized id the same 404 body', function () use ($stats): void {
    $ids = $this->seedCatalog();
    $event = $ids['published'][0];
    $stranger = User::factory()->create(['role' => UserRole::Organizer])->id;

    $stats->call($this, $ids['organizer'], $event)->assertOk();

    $bodies = [];

    foreach ([[$stranger, $event], [$ids['organizer'], 999999999], [$ids['organizer'], '12345678901234567890']] as [$actor, $id]) {
        $this->app['auth']->forgetGuards();
        $bodies[] = $stats->call($this, $actor, $id)->assertStatus(404)->getContent();
    }

    expect($bodies[0])->toBe('{"error":{"code":"NOT_FOUND","message":"The requested resource was not found."}}')
        ->and($bodies[1])->toBe($bodies[0])
        ->and($bodies[2])->toBe($bodies[0]);
});

it('gives a buyer 403 FORBIDDEN, never 404', function (): void {
    $ids = $this->seedCatalog();
    $buyer = User::factory()->create(['role' => UserRole::Buyer]);

    $this->actingAs($buyer, 'sanctum')
        ->getJson('/api/v1/organizer/events/'.$ids['published'][0].'/stats')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'FORBIDDEN');
});

it('reports the real seat counts of an archived event', function () use ($stats): void {
    $ids = $this->seedCatalog();
    $archived = $ids['archived'];

    DB::table('event_seats')->insert(array_map(fn (int $number): array => [
        'event_id' => $archived,
        'section' => 'A',
        'row' => 1,
        'number' => $number,
        'x' => $number * 40,
        'y' => 40,
        'price_cents' => 2500,
        'currency' => 'EUR',
        'status' => $number <= 3 ? 'sold' : 'free',
    ], range(1, 5)));

    $response = $stats->call($this, $ids['organizer'], $archived)->assertOk();

    expect($response->json())->toBe([
        'event_id' => $archived,
        'seats_total' => 5,
        'seats_sold' => 3,
        'seats_free' => 2,
        'revenue_cents' => 0,
        'currency' => 'EUR',
    ]);
});
