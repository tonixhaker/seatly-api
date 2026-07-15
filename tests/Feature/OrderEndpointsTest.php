<?php

declare(strict_types=1);

use App\Domain\Order\Contracts\PaymentGatewayInterface;
use App\Domain\Order\DTO\PaymentResult;
use App\Domain\User\Enums\UserRole;
use App\Domain\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

uses(RefreshDatabase::class);

function buyerUser(): User
{
    return User::factory()->create(['role' => 'buyer']);
}

function orderPayload(array $overrides = []): array
{
    return array_merge([
        'event_id' => 1,
        'seat_ids' => [1, 2],
        'session_id' => '11111111-2222-4333-8444-555555555555',
        'idempotency_key' => 'key-abc',
    ], $overrides);
}

$approveEveryCharge = static function (): void {
    app()->instance(PaymentGatewayInterface::class, new class implements PaymentGatewayInterface
    {
        public function charge(int $amountCents, string $currency): PaymentResult
        {
            return PaymentResult::approved($amountCents, $currency);
        }
    });
};

$holds = new stdClass;

$fakeHolds = static function (mixed $body, int $status = 200) use ($holds): void {
    $holds->body = $body;
    $holds->status = $status;
};

$place = static function (TestCase $case, array $world, array $overrides = []) {
    return $case->actingAs($world['buyer'], 'sanctum')->postJson('/api/v1/orders', orderPayload(array_merge([
        'event_id' => $world['event'],
        'seat_ids' => $world['seats'],
    ], $overrides)));
};

beforeEach(function () use ($holds, $fakeHolds, $approveEveryCharge): void {
    $fakeHolds(['valid' => true, 'missing' => []]);
    $approveEveryCharge();

    Http::preventStrayRequests();
    Http::fake(function () use ($holds): mixed {
        if ($holds->body instanceof Closure) {
            return ($holds->body)();
        }

        return Http::response($holds->body, $holds->status);
    });
});

it('factory make casts the role attribute to the UserRole enum', function (): void {
    expect(User::factory()->make(['role' => 'buyer'])->getAttribute('role'))->toBe(UserRole::Buyer);
});

it('401 without a token', function (): void {
    $this->postJson('/api/v1/orders', orderPayload())->assertStatus(401)->assertJsonPath('error.code', 'UNAUTHENTICATED');
    $this->getJson('/api/v1/my/tickets')->assertStatus(401);
});

it('403 for an organizer and a role-less user', function (): void {
    $this->actingAs(User::factory()->make(['role' => 'organizer']), 'sanctum')
        ->getJson('/api/v1/my/tickets')->assertStatus(403)->assertJsonPath('error.code', 'FORBIDDEN');

    $this->actingAs(User::factory()->make(['role' => null]), 'sanctum')
        ->postJson('/api/v1/orders', orderPayload())->assertStatus(403)->assertJsonPath('error.code', 'FORBIDDEN');
});

it('201 with the exact order shape and no session_id', function (): void {
    $world = $this->seedPurchasable(2);

    $response = $this->actingAs($world['buyer'], 'sanctum')->postJson('/api/v1/orders', orderPayload([
        'event_id' => $world['event'],
        'seat_ids' => $world['seats'],
    ]))->assertStatus(201);

    expect(array_keys((array) $response->json()))->toBe(['id', 'event_id', 'status', 'total_cents', 'currency', 'items'])
        ->and($response->json('status'))->toBe('paid')
        ->and($response->json('total_cents'))->toBe(2 * $world['price'])
        ->and($response->json('items'))->toBe([
            ['event_seat_id' => $world['seats'][0], 'price_cents' => $world['price']],
            ['event_seat_id' => $world['seats'][1], 'price_cents' => $world['price']],
        ])
        ->and($response->getContent())->not->toContain('session_id')
        ->not->toContain('11111111-2222-4333-8444-555555555555');
});

it('SEATS_NOT_HELD reports only the missing seats realtime named', function () use ($fakeHolds): void {
    $fakeHolds(['valid' => false, 'missing' => [3, 7]]);

    $this->actingAs(buyerUser(), 'sanctum')
        ->postJson('/api/v1/orders', orderPayload(['seat_ids' => [1, 3, 7]]))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'SEATS_NOT_HELD')
        ->assertJsonPath('error.details.seats', [3, 7]);
});

it('asks realtime with the event, seats and session the buyer actually sent', function (): void {
    $world = $this->seedPurchasable(2);
    $sessionId = '7c9e6679-7425-40de-944b-e07fc1f90ae7';
    [$first, $second] = $world['seats'];

    $this->actingAs($world['buyer'], 'sanctum')->postJson('/api/v1/orders', orderPayload([
        'event_id' => $world['event'],
        'seat_ids' => [$second, $first],
        'session_id' => $sessionId,
    ]))->assertStatus(201);

    Http::assertSent(function ($request) use ($sessionId, $world, $first, $second): bool {
        $url = $request->url();

        expect($url)->toContain('event_id='.$world['event'])
            ->and($url)->toContain('seat_ids='.$second.'&seat_ids='.$first)
            ->and($url)->toContain('session_id='.$sessionId)
            ->and($url)->not->toContain('%5B');

        return true;
    });
});

it('answers 503 SERVICE_UNAVAILABLE when realtime is unreachable, never 500 or SEATS_NOT_HELD', function () use ($fakeHolds): void {
    $fakeHolds(fn (): never => throw new ConnectionException('cURL error 7: Failed to connect'));

    $response = $this->actingAs(buyerUser(), 'sanctum')
        ->postJson('/api/v1/orders', orderPayload())
        ->assertStatus(503);

    expect($response->json('error.code'))->toBe('SERVICE_UNAVAILABLE')
        ->and($response->json('error.code'))->not->toBe('SEATS_NOT_HELD')
        ->and(array_keys((array) $response->json('error')))->toBe(['code', 'message']);
});

it('answers 503 on a 401 from realtime, with a body identical to the outage', function () use ($fakeHolds): void {
    $fakeHolds(['error' => ['code' => 'UNAUTHENTICATED']], 401);

    $unauthorized = $this->actingAs(buyerUser(), 'sanctum')
        ->postJson('/api/v1/orders', orderPayload())->assertStatus(503);

    $fakeHolds(fn (): never => throw new ConnectionException('cURL error 7: Failed to connect'));

    $unreachable = $this->actingAs(buyerUser(), 'sanctum')
        ->postJson('/api/v1/orders', orderPayload())->assertStatus(503);

    expect($unauthorized->json('error'))->toBe($unreachable->json('error'))
        ->and($unauthorized->json('error.code'))->toBe('SERVICE_UNAVAILABLE');
});

it('distinguishes a wrong token from a missing seat in the log, not in the body', function () use ($fakeHolds): void {
    $records = [];
    Log::listen(function (MessageLogged $entry) use (&$records): void {
        $records[] = $entry;
    });

    $fakeHolds(['error' => ['code' => 'UNAUTHENTICATED']], 401);
    $unauthorized = $this->actingAs(buyerUser(), 'sanctum')
        ->postJson('/api/v1/orders', orderPayload())->assertStatus(503);

    $flagged = array_values(array_filter(
        $records,
        static fn (MessageLogged $entry): bool => ($entry->context['reason'] ?? null) === 'unauthorized',
    ));

    expect($flagged)->toHaveCount(1)
        ->and($flagged[0]->level)->toBe('error')
        ->and($flagged[0]->context['status'])->toBe(401);

    $records = [];

    $fakeHolds(['valid' => false, 'missing' => [3]]);
    $missing = $this->actingAs(buyerUser(), 'sanctum')
        ->postJson('/api/v1/orders', orderPayload())->assertStatus(422);

    expect(array_filter(
        $records,
        static fn (MessageLogged $entry): bool => isset($entry->context['reason']),
    ))->toBe([])
        ->and($missing->json('error.code'))->toBe('SEATS_NOT_HELD')
        ->and($unauthorized->json('error.code'))->toBe('SERVICE_UNAVAILABLE');
});

it('leaks the internal token into no response body and no log record', function () use ($fakeHolds): void {
    $token = 'grep-for-this-internal-token';
    config(['realtime.internal_token' => $token]);

    $records = [];
    Log::listen(function (MessageLogged $entry) use (&$records): void {
        $records[] = $entry;
    });

    $bodies = [];

    $fakeHolds(['error' => ['code' => 'UNAUTHENTICATED']], 401);
    $bodies[] = $this->actingAs(buyerUser(), 'sanctum')->postJson('/api/v1/orders', orderPayload())->getContent();

    $fakeHolds(fn (): never => throw new ConnectionException('cURL error 7: Failed to connect'));
    $bodies[] = $this->actingAs(buyerUser(), 'sanctum')->postJson('/api/v1/orders', orderPayload())->getContent();

    $fakeHolds(['valid' => false, 'missing' => [3]]);
    $bodies[] = $this->actingAs(buyerUser(), 'sanctum')->postJson('/api/v1/orders', orderPayload())->getContent();

    foreach ($bodies as $body) {
        expect((string) $body)->not->toContain($token);
    }

    foreach ($records as $entry) {
        expect(json_encode([$entry->message, $entry->context], JSON_THROW_ON_ERROR))->not->toContain($token);
    }

    expect($records)->not->toBe([]);
});

it('validation failures', function (array $overrides, string $field): void {
    $this->actingAs(buyerUser(), 'sanctum')
        ->postJson('/api/v1/orders', orderPayload($overrides))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED')
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with([
    'empty seat_ids' => [['seat_ids' => []], 'seat_ids'],
    'non-uuid session_id' => [['session_id' => 'not-a-uuid'], 'session_id'],
    'duplicate seat ids' => [['seat_ids' => [1, 1]], 'seat_ids.1'],
]);

it('reads the buyer own order back with every item and the correct total', function () use ($place): void {
    $world = $this->seedPurchasable(2);

    $created = $place($this, $world, ['idempotency_key' => 'read-back'])->assertStatus(201);
    $orderId = (string) $created->json('id');

    $own = $this->actingAs($world['buyer'], 'sanctum')->getJson('/api/v1/orders/'.$orderId)->assertStatus(200);

    expect($own->json('id'))->toBe($orderId)
        ->and($own->json('event_id'))->toBe($world['event'])
        ->and($own->json('status'))->toBe('paid')
        ->and($own->json('currency'))->toBe('EUR')
        ->and($own->json('total_cents'))->toBe(2 * $world['price'])
        ->and($own->json('items'))->toBe([
            ['event_seat_id' => $world['seats'][0], 'price_cents' => $world['price']],
            ['event_seat_id' => $world['seats'][1], 'price_cents' => $world['price']],
        ])
        ->and($own->getContent())->toBe($created->getContent());
});

it('ownership is 404 never 403, and the foreign 404 is byte-identical to the unknown one', function () use ($place): void {
    $world = $this->seedPurchasable(2);

    $orderId = (string) $place($this, $world, ['idempotency_key' => 'hidden-order'])->assertStatus(201)->json('id');

    $stranger = User::factory()->create(['role' => 'buyer']);
    $this->app['auth']->forgetGuards();

    $foreign = $this->actingAs($stranger, 'sanctum')->getJson('/api/v1/orders/'.$orderId)->assertStatus(404);
    $unknown = $this->actingAs($stranger, 'sanctum')
        ->getJson('/api/v1/orders/7c2d9e50-1a3b-4c5d-8e9f-0b1c2d3e4f56')->assertStatus(404);

    expect($foreign->getContent())->toBe($unknown->getContent())
        ->and($foreign->json('error.code'))->toBe('NOT_FOUND')
        ->and($foreign->getContent())->not->toContain($orderId)
        ->and($foreign->getStatusCode())->not->toBe(403);

    $this->app['auth']->forgetGuards();

    $forbidden = $this->actingAs(User::factory()->make(['role' => 'organizer']), 'sanctum')
        ->getJson('/api/v1/orders/'.$orderId)->assertStatus(403);

    expect($forbidden->json('error.code'))->toBe('FORBIDDEN')
        ->and($forbidden->getStatusCode())->not->toBe($foreign->getStatusCode());

    $this->actingAs($world['buyer'], 'sanctum')->getJson('/api/v1/orders/abc')->assertStatus(404);
});

it('tickets is a bare array carrying both statuses and the frozen timestamp shape', function () use ($place): void {
    $world = $this->seedPurchasable(2);

    $orderId = (string) $place($this, $world, ['idempotency_key' => 'ticket-shape'])->assertStatus(201)->json('id');

    DB::table('tickets')
        ->where('event_seat_id', $world['seats'][1])
        ->update(['status' => 'checked_in', 'checked_in_at' => '2026-10-01 18:42:07']);

    $response = $this->actingAs($world['buyer'], 'sanctum')->getJson('/api/v1/my/tickets')->assertStatus(200);

    expect($response->json('data'))->toBeNull()
        ->and($response->json())->toHaveCount(2)
        ->and(array_keys((array) $response->json('0')))->toBe(['id', 'order_id', 'event_seat_id', 'qr_code', 'status', 'checked_in_at'])
        ->and($response->json('0.order_id'))->toBe($orderId)
        ->and($response->json('0.event_seat_id'))->toBe($world['seats'][0])
        ->and($response->json('0.status'))->toBe('issued')
        ->and($response->json('0.checked_in_at'))->toBeNull()
        ->and($response->json('1.event_seat_id'))->toBe($world['seats'][1])
        ->and($response->json('1.status'))->toBe('checked_in')
        ->and($response->json('1.checked_in_at'))->toBe('2026-10-01T18:42:07Z')
        ->and($response->getContent())->not->toContain('session_id');
});

it('answers an empty array for a buyer who has bought nothing', function (): void {
    $response = $this->actingAs(buyerUser(), 'sanctum')->getJson('/api/v1/my/tickets')->assertStatus(200);

    expect($response->json())->toBe([])
        ->and($response->getContent())->toBe('[]');
});

it('returns exactly the caller tickets and never another buyer qr code', function () use ($place): void {
    $mine = $this->seedPurchasable(2);
    $theirs = $this->seedPurchasable(1);

    $place($this, $mine, ['idempotency_key' => 'mine'])->assertStatus(201);
    $this->app['auth']->forgetGuards();
    $place($this, $theirs, ['idempotency_key' => 'theirs'])->assertStatus(201);
    $this->app['auth']->forgetGuards();

    $foreignTickets = DB::table('tickets')
        ->join('orders', 'orders.id', '=', 'tickets.order_id')
        ->where('orders.buyer_id', $theirs['buyer']->id)
        ->get(['tickets.id', 'tickets.qr_code']);

    expect($foreignTickets)->toHaveCount(1);

    $response = $this->actingAs($mine['buyer'], 'sanctum')->getJson('/api/v1/my/tickets')->assertStatus(200);
    $body = (string) $response->getContent();

    expect($response->json('*.event_seat_id'))->toBe($mine['seats']);

    foreach ($foreignTickets as $ticket) {
        expect($body)->not->toContain((string) $ticket->qr_code)
            ->and($body)->not->toContain((string) $ticket->id);
    }
});

it('orders tickets by the clause the query declares, not by whatever the heap returns', function () use ($place): void {
    $world = $this->seedPurchasable(2);

    $place($this, $world, ['idempotency_key' => 'ordering'])->assertStatus(201);

    DB::connection()->flushQueryLog();
    DB::connection()->enableQueryLog();

    $this->actingAs($world['buyer'], 'sanctum')->getJson('/api/v1/my/tickets')->assertStatus(200);

    $log = DB::connection()->getQueryLog();
    DB::connection()->disableQueryLog();

    $reads = array_values(array_filter(
        array_column($log, 'query'),
        static fn (string $sql): bool => str_contains($sql, 'from "tickets"'),
    ));

    expect($reads)->toHaveCount(1)
        ->and($reads[0])->toContain('order by "tickets"."created_at" asc, "tickets"."event_seat_id" asc, "tickets"."id" asc')
        ->and($reads[0])->toContain('where "orders"."buyer_id" = ?');
});

it('reports the status the order actually has, not a hardcoded paid', function (): void {
    $this->instance(PaymentGatewayInterface::class, new class implements PaymentGatewayInterface
    {
        public function charge(int $amountCents, string $currency): PaymentResult
        {
            return PaymentResult::declined($amountCents, $currency);
        }
    });

    $world = $this->seedPurchasable(1);

    $this->actingAs($world['buyer'], 'sanctum')->postJson('/api/v1/orders', orderPayload([
        'event_id' => $world['event'],
        'seat_ids' => $world['seats'],
        'idempotency_key' => 'declined-order',
    ]))->assertStatus(402);

    $orderId = (string) DB::table('orders')->where('buyer_id', $world['buyer']->id)->value('id');

    $response = $this->actingAs($world['buyer'], 'sanctum')->getJson('/api/v1/orders/'.$orderId)->assertStatus(200);

    expect($response->json('status'))->toBe('payment_failed')
        ->and($this->actingAs($world['buyer'], 'sanctum')->getJson('/api/v1/my/tickets')->json())->toBe([]);
});

it('hostile input never 500s', function (mixed $overrides): void {
    $status = $this->actingAs(buyerUser(), 'sanctum')->postJson('/api/v1/orders', orderPayload($overrides))->getStatusCode();

    expect($status)->toBe(422);
})->with([
    [['event_id' => '99999999999999999999']],
    [['event_id' => 'abc']],
    [['seat_ids' => 'nope']],
    [['seat_ids' => [['nested']]]],
    [['seat_ids' => ['x']]],
    [['session_id' => ['a']]],
    [['idempotency_key' => ['a']]],
]);

dataset('buyerRoutes', [
    'POST /orders' => ['post', '/api/v1/orders'],
    'GET /orders/{id}' => ['get', '/api/v1/orders/7c2d9e50-1a3b-4c5d-8e9f-0b1c2d3e4f56'],
    'GET /my/tickets' => ['get', '/api/v1/my/tickets'],
]);

it('401 without a token on every buyer route, with no details key', function (string $method, string $uri): void {
    $response = $this->json($method, $uri, orderPayload())
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'UNAUTHENTICATED');

    expect(array_keys((array) $response->json('error')))->toBe(['code', 'message']);
})->with('buyerRoutes');

it('403 on every buyer route for every non-buyer, with no details key', function (string $method, string $uri): void {
    foreach ([['role' => 'organizer'], ['role' => null]] as $attributes) {
        $response = $this->actingAs(User::factory()->make($attributes), 'sanctum')
            ->json($method, $uri, orderPayload())
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');

        expect(array_keys((array) $response->json('error')))->toBe(['code', 'message']);
    }
})->with('buyerRoutes');

it('422 naming the field when a required field is missing', function (string $field): void {
    $payload = orderPayload();
    unset($payload[$field]);

    $this->actingAs(buyerUser(), 'sanctum')
        ->postJson('/api/v1/orders', $payload)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED')
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with(['event_id', 'seat_ids', 'session_id', 'idempotency_key']);

it('422 naming the field the rule rejected', function (array $overrides, string $field): void {
    $this->actingAs(buyerUser(), 'sanctum')
        ->postJson('/api/v1/orders', orderPayload($overrides))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED')
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with([
    'non-integer seat id' => [['seat_ids' => ['x']], 'seat_ids.0'],
    'decimal seat id' => [['seat_ids' => [1.5]], 'seat_ids.0'],
    'zero seat id' => [['seat_ids' => [0]], 'seat_ids.0'],
    'negative seat id' => [['seat_ids' => [-5]], 'seat_ids.0'],
    'zero event_id' => [['event_id' => 0], 'event_id'],
    'seat_ids not an array' => [['seat_ids' => 'nope'], 'seat_ids'],
    'idempotency_key over 128 characters' => [['idempotency_key' => str_repeat('k', 129)], 'idempotency_key'],
]);

it('accepts an idempotency key of exactly 128 characters', function (): void {
    $world = $this->seedPurchasable(2);

    $this->actingAs($world['buyer'], 'sanctum')
        ->postJson('/api/v1/orders', orderPayload([
            'event_id' => $world['event'],
            'seat_ids' => $world['seats'],
            'idempotency_key' => str_repeat('k', 128),
        ]))
        ->assertStatus(201);
});

it('405 METHOD_NOT_ALLOWED on a wrong verb against a buyer route', function (): void {
    $this->getJson('/api/v1/orders')
        ->assertStatus(405)
        ->assertJsonPath('error.code', 'METHOD_NOT_ALLOWED');

    $this->deleteJson('/api/v1/orders/7c2d9e50-1a3b-4c5d-8e9f-0b1c2d3e4f56')
        ->assertStatus(405)
        ->assertJsonPath('error.code', 'METHOD_NOT_ALLOWED');
});

it('404 with no details key for an unknown order id in either case', function (): void {
    foreach (['7c2d9e50-1a3b-4c5d-8e9f-0b1c2d3e4f56', '7C2D9E50-1A3B-4C5D-8E9F-0B1C2D3E4F56'] as $unknown) {
        $response = $this->actingAs(buyerUser(), 'sanctum')
            ->getJson('/api/v1/orders/'.$unknown)
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND');

        expect(array_keys((array) $response->json('error')))->toBe(['code', 'message']);
    }
});

it('resolves the buyer own order through an uppercase uuid, because uuids are case-insensitive', function () use ($place): void {
    $world = $this->seedPurchasable(2);

    $orderId = (string) $place($this, $world, ['idempotency_key' => 'uppercase'])->assertStatus(201)->json('id');

    $lower = $this->actingAs($world['buyer'], 'sanctum')->getJson('/api/v1/orders/'.$orderId)->assertStatus(200);
    $upper = $this->actingAs($world['buyer'], 'sanctum')->getJson('/api/v1/orders/'.strtoupper($orderId))->assertStatus(200);

    expect($upper->getContent())->toBe($lower->getContent());
});

it('never echoes the session id on any of the three routes', function () use ($place): void {
    $sessionId = '11111111-2222-4333-8444-555555555555';

    $world = $this->seedPurchasable(2);

    $created = $place($this, $world, ['session_id' => $sessionId, 'idempotency_key' => 'session-echo'])->assertStatus(201);
    $orderId = (string) $created->json('id');

    $responses = [
        $created,
        $this->actingAs($world['buyer'], 'sanctum')->getJson('/api/v1/orders/'.$orderId),
        $this->actingAs($world['buyer'], 'sanctum')->getJson('/api/v1/my/tickets'),
    ];

    foreach ($responses as $response) {
        expect($response->getContent())->not->toContain('session_id')->not->toContain($sessionId);
    }
});

it('issues a unique twelve character qr code per ticket, and never the milestone-01 fixture', function () use ($place): void {
    $world = $this->seedPurchasable(2);

    $place($this, $world, ['idempotency_key' => 'qr-codes'])->assertStatus(201);

    $response = $this->actingAs($world['buyer'], 'sanctum')->getJson('/api/v1/my/tickets')->assertStatus(200);
    $codes = (array) $response->json('*.qr_code');

    expect($codes)->toHaveCount(2)
        ->and(array_unique($codes))->toHaveCount(count($codes))
        ->and($response->getContent())->not->toContain('A1B2C3D4E5F6')
        ->and($response->getContent())->not->toContain('G7H8J9K0L1M2');

    $stored = DB::table('tickets')->orderBy('event_seat_id')->pluck('qr_code')->all();

    expect($codes)->toBe($stored);

    foreach ($codes as $code) {
        expect($code)->toBeString()->toMatch('/^[0-9A-F]{12}$/');
    }
});

it('rejects a boolean seat id instead of fabricating seat zero', function (): void {
    $this->actingAs(buyerUser(), 'sanctum')
        ->postJson('/api/v1/orders', orderPayload(['seat_ids' => [true]]))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED')
        ->assertJsonStructure(['error' => ['details' => ['seat_ids.0']]]);
});

it('rejects a boolean event id', function (): void {
    $this->actingAs(buyerUser(), 'sanctum')
        ->postJson('/api/v1/orders', orderPayload(['event_id' => true]))
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['details' => ['event_id']]]);
});

it('rejects a string-keyed seat_ids object so error keys stay positional', function (): void {
    $this->actingAs(buyerUser(), 'sanctum')
        ->postJson('/api/v1/orders', orderPayload(['seat_ids' => ['a' => 1, 'b' => 2]]))
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['details' => ['seat_ids']]]);
});

it('caps seat_ids at fifty and stays fast past the cap', function (): void {
    $world = $this->seedPurchasable(50, 0);

    $this->actingAs($world['buyer'], 'sanctum')
        ->postJson('/api/v1/orders', orderPayload([
            'event_id' => $world['event'],
            'seat_ids' => $world['seats'],
        ]))
        ->assertStatus(201);

    $this->actingAs($world['buyer'], 'sanctum')
        ->postJson('/api/v1/orders', orderPayload([
            'event_id' => $world['event'],
            'seat_ids' => range(100, 150),
            'idempotency_key' => 'over-the-cap',
        ]))
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['details' => ['seat_ids']]]);
});

it('reads the items back in the order the seats were bought, not sorted by seat id', function () use ($place): void {
    $world = $this->seedPurchasable(3);
    [$first, $second, $third] = $world['seats'];
    $bought = [$third, $first, $second];

    $created = $place($this, $world, ['seat_ids' => $bought, 'idempotency_key' => 'shuffled-items'])->assertStatus(201);
    $orderId = (string) $created->json('id');

    $own = $this->actingAs($world['buyer'], 'sanctum')->getJson('/api/v1/orders/'.$orderId)->assertStatus(200);

    expect($own->json('items.*.event_seat_id'))->toBe($bought)
        ->and($own->json('items.*.event_seat_id'))->not->toBe([$first, $second, $third])
        ->and($own->json('total_cents'))->toBe(3 * $world['price'])
        ->and($own->getContent())->toBe($created->getContent());
});

it('lists the tickets of every order the buyer has, oldest purchase first', function () use ($place): void {
    $world = $this->seedPurchasable(2, 2);

    $later = (string) $place($this, $world, ['idempotency_key' => 'later-purchase'])->assertStatus(201)->json('id');
    $earlier = (string) $place($this, $world, ['seat_ids' => $world['spare'], 'idempotency_key' => 'earlier-purchase'])->assertStatus(201)->json('id');

    DB::table('tickets')->where('order_id', $later)->update(['created_at' => '2026-02-02 09:00:00']);
    DB::table('tickets')->where('order_id', $earlier)->update(['created_at' => '2026-01-01 09:00:00']);

    $response = $this->actingAs($world['buyer'], 'sanctum')->getJson('/api/v1/my/tickets')->assertStatus(200);

    expect($response->json())->toHaveCount(4)
        ->and($response->json('*.event_seat_id'))->toBe([...$world['spare'], ...$world['seats']])
        ->and($response->json('*.order_id'))->toBe([$earlier, $earlier, $later, $later]);
});

it('formats every checked-in timestamp from its own row, never one frozen value', function () use ($place): void {
    $world = $this->seedPurchasable(2);

    $place($this, $world, ['idempotency_key' => 'two-check-ins'])->assertStatus(201);

    DB::table('tickets')->where('event_seat_id', $world['seats'][0])
        ->update(['status' => 'checked_in', 'checked_in_at' => '2026-03-04 05:06:07']);
    DB::table('tickets')->where('event_seat_id', $world['seats'][1])
        ->update(['status' => 'checked_in', 'checked_in_at' => '2027-11-30 23:59:58']);

    $response = $this->actingAs($world['buyer'], 'sanctum')->getJson('/api/v1/my/tickets')->assertStatus(200);

    expect($response->json('*.checked_in_at'))->toBe(['2026-03-04T05:06:07Z', '2027-11-30T23:59:58Z']);
});
