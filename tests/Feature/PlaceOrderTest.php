<?php

declare(strict_types=1);

use App\Domain\Order\Contracts\PaymentGatewayInterface;
use App\Domain\Order\DTO\PaymentResult;
use App\Domain\Shared\Contracts\DomainEvent;
use App\Domain\Shared\Contracts\EventPublisherInterface;
use App\Domain\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Random\Engine;
use Random\Randomizer;

uses(RefreshDatabase::class);

$gateway = static function (bool $approved = true): object {
    return new class($approved) implements PaymentGatewayInterface
    {
        public int $charges = 0;

        public function __construct(public bool $approves) {}

        public function charge(int $amountCents, string $currency): PaymentResult
        {
            $this->charges++;

            return $this->approves
                ? PaymentResult::approved($amountCents, $currency)
                : PaymentResult::declined($amountCents, $currency);
        }
    };
};

$spy = static function (?Throwable $throws = null): object {
    return new class($throws) implements EventPublisherInterface
    {
        /** @var list<array{type: string, payload: array<string, mixed>, level: int}> */
        public array $published = [];

        public function __construct(private readonly ?Throwable $throws) {}

        public function publish(DomainEvent $event): void
        {
            $this->published[] = [
                'type' => $event->type(),
                'payload' => $event->payload(),
                'level' => DB::transactionLevel(),
            ];

            if ($this->throws !== null) {
                throw $this->throws;
            }
        }
    };
};

$payload = static function (array $world, array $overrides = []): array {
    return array_merge([
        'event_id' => $world['event'],
        'seat_ids' => $world['seats'],
        'session_id' => '11111111-2222-4333-8444-555555555555',
        'idempotency_key' => 'key-'.bin2hex(random_bytes(6)),
    ], $overrides);
};

$holds = new stdClass;

beforeEach(function () use ($holds): void {
    $holds->body = ['valid' => true, 'missing' => []];

    Http::preventStrayRequests();
    Http::fake(fn (): mixed => Http::response($holds->body, 200));
});

it('places a real order whose body is pinned to the database, not to a fixture', function () use ($gateway, $payload): void {
    $world = $this->seedPurchasable(2);
    $this->instance(PaymentGatewayInterface::class, $gateway());

    $response = $this->actingAs($world['buyer'], 'sanctum')
        ->postJson('/api/v1/orders', $payload($world))
        ->assertStatus(201);

    $row = DB::table('orders')->sole();

    expect($response->json('id'))->toBe($row->id)
        ->and($response->json('total_cents'))->toBe(2 * $world['price'])
        ->and($world['price'])->not->toBe(5000)
        ->and($world['price'])->not->toBe(3500)
        ->and($response->json('currency'))->toBe('EUR')
        ->and($response->json('status'))->toBe('paid');
});

it('snapshots the seat price into order_items rather than referencing the seat', function () use ($gateway, $payload): void {
    $world = $this->seedPurchasable(2);
    $this->instance(PaymentGatewayInterface::class, $gateway());

    $this->actingAs($world['buyer'], 'sanctum')->postJson('/api/v1/orders', $payload($world))->assertStatus(201);

    DB::table('event_seats')->whereIn('id', $world['seats'])->update(['price_cents' => 99]);

    $snapshotted = DB::table('order_items')->orderBy('id')->pluck('price_cents')->map(intval(...))->all();

    expect($snapshotted)->toBe([$world['price'], $world['price']]);
});

it('rejects a seat list that mixes currencies rather than summing a meaningless total', function () use ($gateway, $payload): void {
    $world = $this->seedPurchasable(2);
    $this->instance(PaymentGatewayInterface::class, $charged = $gateway());

    DB::table('event_seats')->where('id', $world['seats'][1])->update(['currency' => 'USD']);

    $this->actingAs($world['buyer'], 'sanctum')
        ->postJson('/api/v1/orders', $payload($world))
        ->assertStatus(422);

    expect(DB::table('orders')->count())->toBe(0)
        ->and($charged->charges)->toBe(0);
});

it('leaves order paid, the bought seats sold, the other seats free and one ticket per seat', function () use ($gateway, $spy, $payload): void {
    $world = $this->seedPurchasable(2, 2);
    $this->instance(PaymentGatewayInterface::class, $gateway());
    $this->instance(EventPublisherInterface::class, $publisher = $spy());

    $this->actingAs($world['buyer'], 'sanctum')->postJson('/api/v1/orders', $payload($world))->assertStatus(201);

    $ticketSeats = DB::table('tickets')->orderBy('event_seat_id')->pluck('event_seat_id')->map(intval(...))->all();
    $sorted = $world['seats'];
    sort($sorted);

    expect(DB::table('orders')->value('status'))->toBe('paid')
        ->and(DB::table('event_seats')->whereIn('id', $world['seats'])->pluck('status')->unique()->all())->toBe(['sold'])
        ->and(DB::table('event_seats')->whereIn('id', $world['spare'])->pluck('status')->unique()->all())->toBe(['free'])
        ->and($ticketSeats)->toBe($sorted)
        ->and(array_column($publisher->published, 'type'))->toBe(['order.paid', 'tickets.issued']);
});

it('publishes order.paid and tickets.issued with the payloads the schemas froze', function () use ($gateway, $spy, $payload): void {
    $world = $this->seedPurchasable(2);
    $this->instance(PaymentGatewayInterface::class, $gateway());
    $this->instance(EventPublisherInterface::class, $publisher = $spy());

    $this->actingAs($world['buyer'], 'sanctum')->postJson('/api/v1/orders', $payload($world))->assertStatus(201);

    $orderId = (string) DB::table('orders')->value('id');
    $ticketIds = DB::table('tickets')->orderBy('event_seat_id')->pluck('id')->all();

    expect($publisher->published[0]['payload'])->toBe([
        'order_id' => $orderId,
        'event_id' => $world['event'],
        'seat_ids' => $world['seats'],
        'buyer_id' => $world['buyer']->id,
    ])
        ->and($publisher->published[1]['payload']['order_id'])->toBe($orderId)
        ->and($publisher->published[1]['payload']['ticket_ids'])->toEqualCanonicalizing($ticketIds);
});

it('answers 402 on a decline and leaves the seats free with no tickets', function () use ($gateway, $spy, $payload): void {
    $world = $this->seedPurchasable(2);
    $this->instance(PaymentGatewayInterface::class, $gateway(approved: false));
    $this->instance(EventPublisherInterface::class, $publisher = $spy());

    $response = $this->actingAs($world['buyer'], 'sanctum')
        ->postJson('/api/v1/orders', $payload($world))
        ->assertStatus(402);

    expect($response->json('error.code'))->toBe('PAYMENT_DECLINED')
        ->and(DB::table('orders')->value('status'))->toBe('payment_failed')
        ->and(DB::table('event_seats')->whereIn('id', $world['seats'])->pluck('status')->unique()->all())->toBe(['free'])
        ->and(DB::table('tickets')->count())->toBe(0)
        ->and(array_column($publisher->published, 'type'))->toBe(['order.payment_failed']);
});

it('never asks realtime to release the hold on a decline', function () use ($gateway, $payload): void {
    $world = $this->seedPurchasable(2);
    $this->instance(PaymentGatewayInterface::class, $gateway(approved: false));

    $this->actingAs($world['buyer'], 'sanctum')->postJson('/api/v1/orders', $payload($world))->assertStatus(402);

    Http::assertSentCount(1);
    Http::assertSent(fn ($request): bool => $request->method() === 'GET' && str_contains($request->url(), '/internal/holds/validate'));
});

it('forwards the X-Request-Id of the order request to the holds validation call', function () use ($gateway, $payload): void {
    $requestId = '3f2b8c1e-5d4a-4e6f-9a7b-1c2d3e4f5a6b';
    $world = $this->seedPurchasable(2);
    $this->instance(PaymentGatewayInterface::class, $gateway());

    $this->actingAs($world['buyer'], 'sanctum')
        ->postJson('/api/v1/orders', $payload($world), ['X-Request-Id' => $requestId])
        ->assertStatus(201);

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/internal/holds/validate')
        && $request->header('X-Request-Id') === [$requestId]);
});

it('lets the buyer retry a declined order against the surviving hold', function () use ($gateway, $payload): void {
    $world = $this->seedPurchasable(2);
    $this->instance(PaymentGatewayInterface::class, $card = $gateway(approved: false));

    $this->actingAs($world['buyer'], 'sanctum')->postJson('/api/v1/orders', $payload($world))->assertStatus(402);

    $card->approves = true;
    $this->actingAs($world['buyer'], 'sanctum')->postJson('/api/v1/orders', $payload($world))->assertStatus(201);

    expect(DB::table('event_seats')->whereIn('id', $world['seats'])->pluck('status')->unique()->all())->toBe(['sold'])
        ->and(DB::table('orders')->where('status', 'paid')->count())->toBe(1)
        ->and(DB::table('tickets')->count())->toBe(2);
});

it('replays an idempotency key with 200, one order, one set of tickets and one charge', function () use ($gateway, $payload, $spy): void {
    $world = $this->seedPurchasable(2);
    $this->instance(PaymentGatewayInterface::class, $charged = $gateway());
    $this->instance(EventPublisherInterface::class, $publisher = $spy());

    $body = $payload($world);

    $first = $this->actingAs($world['buyer'], 'sanctum')->postJson('/api/v1/orders', $body)->assertStatus(201);
    $second = $this->actingAs($world['buyer'], 'sanctum')->postJson('/api/v1/orders', $body)->assertStatus(200);

    expect($second->json('id'))->toBe($first->json('id'))
        ->and($second->json())->toBe($first->json())
        ->and(DB::table('orders')->count())->toBe(1)
        ->and(DB::table('tickets')->count())->toBe(2)
        ->and($charged->charges)->toBe(1)
        ->and($publisher->published)->toHaveCount(2);
});

it('never hands one buyer the order of another buyer who used the same idempotency key', function () use ($gateway, $payload): void {
    $world = $this->seedPurchasable(2, 2);
    $this->instance(PaymentGatewayInterface::class, $gateway());

    $body = $payload($world, ['idempotency_key' => 'shared-key']);
    $first = $this->actingAs($world['buyer'], 'sanctum')->postJson('/api/v1/orders', $body)->assertStatus(201);

    $intruder = User::factory()->create(['role' => 'buyer']);
    $this->app['auth']->forgetGuards();

    $response = $this->actingAs($intruder, 'sanctum')->postJson('/api/v1/orders', $payload($world, [
        'seat_ids' => $world['spare'],
        'idempotency_key' => 'shared-key',
    ]))->assertStatus(422);

    expect($response->getContent())->not->toContain((string) $first->json('id'))
        ->and($response->json('error.code'))->toBe('VALIDATION_FAILED')
        ->and($response->json('error.details'))->toHaveKey('idempotency_key')
        ->and(DB::table('orders')->count())->toBe(1);
});

it('commits the order even when the publisher throws, and publishes outside the transaction', function () use ($gateway, $spy, $payload): void {
    $world = $this->seedPurchasable(2);
    $this->instance(PaymentGatewayInterface::class, $gateway());
    $this->instance(EventPublisherInterface::class, $publisher = $spy(new RuntimeException('broker is on fire')));

    $level = DB::transactionLevel();

    try {
        $this->actingAs($world['buyer'], 'sanctum')->postJson('/api/v1/orders', $payload($world));
    } catch (RuntimeException) {
    }

    expect(DB::table('orders')->value('status'))->toBe('paid')
        ->and(DB::table('tickets')->count())->toBe(2)
        ->and($publisher->published[0]['level'])->toBe($level);
});

it('publishes the decline outside the transaction too', function () use ($gateway, $spy, $payload): void {
    $world = $this->seedPurchasable(2);
    $this->instance(PaymentGatewayInterface::class, $gateway(approved: false));
    $this->instance(EventPublisherInterface::class, $publisher = $spy(new RuntimeException('broker is on fire')));

    $level = DB::transactionLevel();

    try {
        $this->actingAs($world['buyer'], 'sanctum')->postJson('/api/v1/orders', $payload($world));
    } catch (RuntimeException) {
    }

    expect(DB::table('orders')->value('status'))->toBe('payment_failed')
        ->and($publisher->published[0]['level'])->toBe($level);
});

it('rejects seats the session does not hold without writing or charging', function () use ($gateway, $payload, $holds): void {
    $world = $this->seedPurchasable(2);
    $this->instance(PaymentGatewayInterface::class, $charged = $gateway());

    $holds->body = ['valid' => false, 'missing' => [$world['seats'][1]]];

    $this->actingAs($world['buyer'], 'sanctum')
        ->postJson('/api/v1/orders', $payload($world))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'SEATS_NOT_HELD')
        ->assertJsonPath('error.details.seats', [$world['seats'][1]]);

    expect(DB::table('orders')->count())->toBe(0)->and($charged->charges)->toBe(0);
});

it('rejects a seat that belongs to a different event', function () use ($gateway, $payload): void {
    $world = $this->seedPurchasable(2);
    $foreign = $this->seedPurchasable(1);
    $this->instance(PaymentGatewayInterface::class, $charged = $gateway());

    $this->actingAs($world['buyer'], 'sanctum')
        ->postJson('/api/v1/orders', $payload($world, ['seat_ids' => [$world['seats'][0], $foreign['seats'][0]]]))
        ->assertStatus(422)
        ->assertJsonPath('error.details.seats', [$foreign['seats'][0]]);

    expect(DB::table('orders')->count())->toBe(0)
        ->and($charged->charges)->toBe(0)
        ->and(DB::table('event_seats')->where('id', $foreign['seats'][0])->value('status'))->toBe('free');
});

it('rejects an unknown seat id', function () use ($gateway, $payload): void {
    $world = $this->seedPurchasable(2);
    $this->instance(PaymentGatewayInterface::class, $gateway());

    $this->actingAs($world['buyer'], 'sanctum')
        ->postJson('/api/v1/orders', $payload($world, ['seat_ids' => [$world['seats'][0], 987654]]))
        ->assertStatus(422)
        ->assertJsonPath('error.details.seats', [987654]);

    expect(DB::table('orders')->count())->toBe(0);
});

it('retries a colliding qr code and still issues distinct codes across a multi-seat order', function () use ($gateway, $payload): void {
    $world = $this->seedPurchasable(2);
    $this->instance(PaymentGatewayInterface::class, $gateway());

    $scripted = new class implements Engine
    {
        public int $draws = 0;

        /** @var list<string> */
        private array $sequence = ['AAAAAAAA', 'AAAAAAAA', 'BBBBBBBB'];

        public function generate(): string
        {
            $byte = $this->sequence[$this->draws] ?? 'ZZZZZZZZ';
            $this->draws++;

            return $byte;
        }
    };

    $this->instance(Randomizer::class, new Randomizer($scripted));

    $this->actingAs($world['buyer'], 'sanctum')->postJson('/api/v1/orders', $payload($world))->assertStatus(201);

    $codes = DB::table('tickets')->orderBy('event_seat_id')->pluck('qr_code')->all();

    expect($scripted->draws)->toBe(3)
        ->and($codes)->toBe([strtoupper(bin2hex('AAAAAA')), strtoupper(bin2hex('BBBBBB'))])
        ->and(array_unique($codes))->toHaveCount(2);

    foreach ($codes as $code) {
        expect($code)->toBeString()->toHaveLength(12);
    }
});

it('gives up rather than looping forever when every generated code collides', function () use ($gateway, $payload): void {
    $world = $this->seedPurchasable(2);
    $this->instance(PaymentGatewayInterface::class, $gateway());

    $constant = new class implements Engine
    {
        public int $draws = 0;

        public function generate(): string
        {
            $this->draws++;

            return 'AAAAAAAA';
        }
    };

    $this->instance(Randomizer::class, new Randomizer($constant));

    $this->actingAs($world['buyer'], 'sanctum')
        ->postJson('/api/v1/orders', $payload($world))
        ->assertStatus(500);

    expect($constant->draws)->toBe(4)
        ->and(DB::table('orders')->count())->toBe(0);
});

it('stamps each order with the buyer who actually sent the request', function () use ($gateway, $payload): void {
    $world = $this->seedPurchasable(2, 2);
    $this->instance(PaymentGatewayInterface::class, $gateway());

    $second = User::factory()->create(['role' => 'buyer']);

    $mine = $this->actingAs($world['buyer'], 'sanctum')
        ->postJson('/api/v1/orders', $payload($world))
        ->assertStatus(201);

    $this->app['auth']->forgetGuards();

    $theirs = $this->actingAs($second, 'sanctum')
        ->postJson('/api/v1/orders', $payload($world, ['seat_ids' => $world['spare']]))
        ->assertStatus(201);

    $buyerOf = fn (string $orderId): int => (int) DB::table('orders')->where('id', $orderId)->value('buyer_id');

    expect($buyerOf((string) $mine->json('id')))->toBe($world['buyer']->id)
        ->and($buyerOf((string) $theirs->json('id')))->toBe($second->id)
        ->and($world['buyer']->id)->not->toBe($second->id);
});

it('stores total_cents as the sum of the item rows that belong to that order', function () use ($gateway, $payload): void {
    $world = $this->seedPurchasable(3);
    $this->instance(PaymentGatewayInterface::class, $gateway());

    $this->actingAs($world['buyer'], 'sanctum')->postJson('/api/v1/orders', $payload($world))->assertStatus(201);

    $order = DB::table('orders')->sole();
    $prices = DB::table('order_items')->where('order_id', $order->id)->pluck('price_cents')->map(intval(...))->all();

    expect($prices)->toHaveCount(3)
        ->and(array_sum($prices))->toBe((int) $order->total_cents)
        ->and((int) $order->total_cents)->toBe(3 * $world['price'])
        ->and(DB::table('order_items')->count())->toBe(3);
});

it('issues every ticket as issued and not yet checked in', function () use ($gateway, $payload): void {
    $world = $this->seedPurchasable(2);
    $this->instance(PaymentGatewayInterface::class, $gateway());

    $response = $this->actingAs($world['buyer'], 'sanctum')
        ->postJson('/api/v1/orders', $payload($world))
        ->assertStatus(201);

    $tickets = DB::table('tickets')->where('order_id', $response->json('id'))->get();

    expect($tickets)->toHaveCount(2)
        ->and($tickets->pluck('status')->unique()->all())->toBe(['issued'])
        ->and($tickets->pluck('checked_in_at')->unique()->all())->toBe([null]);
});

it('never echoes the session id on the 200 replay or the 402 decline', function () use ($gateway, $payload): void {
    $world = $this->seedPurchasable(2);
    $this->instance(PaymentGatewayInterface::class, $card = $gateway(approved: false));

    $session = '9f8e7d6c-5b4a-4938-8271-615243342516';

    $declined = $this->actingAs($world['buyer'], 'sanctum')
        ->postJson('/api/v1/orders', $payload($world, ['session_id' => $session]))
        ->assertStatus(402);

    $card->approves = true;
    $retry = $payload($world, ['session_id' => $session]);

    $this->actingAs($world['buyer'], 'sanctum')->postJson('/api/v1/orders', $retry)->assertStatus(201);
    $replayed = $this->actingAs($world['buyer'], 'sanctum')->postJson('/api/v1/orders', $retry)->assertStatus(200);

    foreach ([$declined, $replayed] as $response) {
        expect((string) $response->getContent())->not->toContain('session_id')->not->toContain($session);
    }
});
