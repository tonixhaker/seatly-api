<?php

declare(strict_types=1);

use App\Domain\Ticket\Repositories\TicketRepositoryInterface;
use App\Domain\User\Enums\UserRole;
use App\Domain\User\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->ids = $this->seedCatalog();
    $this->owner = User::findOrFail($this->ids['organizer']);
    $this->symphony = $this->ids['published'][0];
    $this->ticketId = $this->insertTicket($this->symphony, $this->ids['sold'][0], 'A1B2C3D4E5F6');
});

$row = fn (string $id): object => DB::table('tickets')->where('id', $id)->sole();

it('checks an issued ticket in and returns exactly the ticket shape', function () use ($row): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-01T19:05:00Z'));

    $response = $this->actingAs($this->owner, 'sanctum')
        ->postJson('/api/v1/organizer/check-in', ['qr_code' => 'A1B2C3D4E5F6'])
        ->assertStatus(200);

    expect(array_keys((array) $response->json()))->toBe(['id', 'order_id', 'event_seat_id', 'qr_code', 'status', 'checked_in_at', 'event', 'seat'])
        ->and($response->json('id'))->toBe($this->ticketId)
        ->and($response->json('event_seat_id'))->toBe($this->ids['sold'][0])
        ->and($response->json('qr_code'))->toBe('A1B2C3D4E5F6')
        ->and($response->json('status'))->toBe('checked_in')
        ->and($response->json('checked_in_at'))->toBe('2026-10-01T19:05:00Z')
        ->and($response->json('event'))->toBe(['id' => $this->symphony, 'title' => 'Autumn Symphony', 'starts_at' => '2026-10-01T19:00:00Z'])
        ->and($response->json('seat'))->toBe(['section' => 'B', 'row' => 1, 'number' => 1]);

    $stored = $row($this->ticketId);

    expect($stored->status)->toBe('checked_in')
        ->and($stored->checked_in_at)->toBe('2026-10-01 19:05:00');
});

it('409 ALREADY_CHECKED_IN on a repeated scan, carrying the first check-in time and never overwriting it', function () use ($row): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-01T19:05:00Z'));

    $first = $this->actingAs($this->owner, 'sanctum')
        ->postJson('/api/v1/organizer/check-in', ['qr_code' => 'A1B2C3D4E5F6'])
        ->assertStatus(200);

    $this->travelTo(CarbonImmutable::parse('2026-10-01T19:40:00Z'));

    $this->actingAs($this->owner, 'sanctum')
        ->postJson('/api/v1/organizer/check-in', ['qr_code' => 'A1B2C3D4E5F6'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'ALREADY_CHECKED_IN')
        ->assertJsonPath('error.details.checked_in_at', $first->json('checked_in_at'));

    expect($first->json('checked_in_at'))->toBe('2026-10-01T19:05:00Z')
        ->and($row($this->ticketId)->checked_in_at)->toBe('2026-10-01 19:05:00');
});

it('409 with the stored time for a ticket that was already checked in before this request', function () use ($row): void {
    $id = $this->insertTicket($this->symphony, $this->ids['sold'][1], 'G7H8J9K0L1M2', 'checked_in', '2026-10-01 18:42:07');

    $this->actingAs($this->owner, 'sanctum')
        ->postJson('/api/v1/organizer/check-in', ['qr_code' => 'G7H8J9K0L1M2'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'ALREADY_CHECKED_IN')
        ->assertJsonPath('error.details.checked_in_at', '2026-10-01T18:42:07Z');

    expect($row($id)->checked_in_at)->toBe('2026-10-01 18:42:07');
});

it('404 NOT_FOUND for a ticket on another organizer event, leaving it issued', function () use ($row): void {
    $stranger = User::factory()->create(['role' => UserRole::Organizer]);

    $this->actingAs($stranger, 'sanctum')
        ->postJson('/api/v1/organizer/check-in', ['qr_code' => 'A1B2C3D4E5F6'])
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'NOT_FOUND');

    $stored = $row($this->ticketId);

    expect($stored->status)->toBe('issued')
        ->and($stored->checked_in_at)->toBeNull();
});

it('makes a foreign ticket byte-identical to a code that matches no ticket', function (): void {
    $stranger = User::factory()->create(['role' => UserRole::Organizer]);

    $foreign = $this->actingAs($stranger, 'sanctum')
        ->postJson('/api/v1/organizer/check-in', ['qr_code' => 'A1B2C3D4E5F6'])
        ->assertStatus(404)
        ->getContent();

    $unknown = $this->actingAs($stranger, 'sanctum')
        ->postJson('/api/v1/organizer/check-in', ['qr_code' => 'Z9Y8X7W6V5U4'])
        ->assertStatus(404)
        ->getContent();

    expect($foreign)->toBe($unknown)
        ->and($unknown)->toBe('{"error":{"code":"NOT_FOUND","message":"The requested resource was not found."}}');
});

it('403 FORBIDDEN for a buyer, leaving the ticket issued', function () use ($row): void {
    $buyer = User::factory()->create(['role' => UserRole::Buyer]);

    $this->actingAs($buyer, 'sanctum')
        ->postJson('/api/v1/organizer/check-in', ['qr_code' => 'A1B2C3D4E5F6'])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'FORBIDDEN');

    expect($row($this->ticketId)->status)->toBe('issued');
});

it('422 VALIDATION_FAILED naming qr_code for an eleven character code', function (): void {
    $this->actingAs($this->owner, 'sanctum')
        ->postJson('/api/v1/organizer/check-in', ['qr_code' => 'A1B2C3D4E5F'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED')
        ->assertJsonStructure(['error' => ['details' => ['qr_code']]]);
});

it('lets only the first of two conditional updates win and never moves the stored time', function () use ($row): void {
    $tickets = app(TicketRepositoryInterface::class);

    $this->travelTo(CarbonImmutable::parse('2026-10-01T19:05:00Z'));
    $first = $tickets->markCheckedIn($this->ticketId);

    $this->travelTo(CarbonImmutable::parse('2026-10-01T19:40:00Z'));
    $second = $tickets->markCheckedIn($this->ticketId);

    $stored = $row($this->ticketId);

    expect($first)->toBeTrue()
        ->and($second)->toBeFalse()
        ->and($stored->status)->toBe('checked_in')
        ->and($stored->checked_in_at)->toBe('2026-10-01 19:05:00');
});
