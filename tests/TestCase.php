<?php

declare(strict_types=1);

namespace Tests;

use App\Domain\User\Enums\UserRole;
use App\Domain\User\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    /**
     * @return array{organizer: int, arena: int, hall: int, published: list<int>, draft: int, archived: int, empty: int, sold: list<int>}
     */
    protected function seedCatalog(): array
    {
        $organizer = User::factory()->create(['role' => UserRole::Organizer]);
        $organizerId = $organizer->id;

        $arena = $this->insertVenue('Riverside Arena', '14 Quay Street', 'Rotterdam');
        $hall = $this->insertVenue('Northgate Hall', '3 Market Square', 'Utrecht');

        $symphony = $this->insertEvent($organizerId, $arena, 'Autumn Symphony', 'An evening of late romantic repertoire.', '2026-10-01 19:00:00', 'published');
        $jazz = $this->insertEvent($organizerId, $hall, 'Winter Jazz Night', 'Three quartets across one long night.', '2026-12-12 20:30:00', 'published');
        $gala = $this->insertEvent($organizerId, $arena, 'Spring Gala', 'Not announced yet.', '2027-03-04 18:00:00', 'draft');
        $retrospective = $this->insertEvent($organizerId, $hall, 'Summer Retrospective', 'Concluded last season.', '2026-06-20 19:30:00', 'archived');
        $empty = $this->insertEvent($organizerId, $arena, 'Seatless Preview', null, '2026-11-05 18:00:00', 'published');

        return [
            'organizer' => $organizerId,
            'arena' => $arena,
            'hall' => $hall,
            'published' => [$symphony, $empty, $jazz],
            'draft' => $gala,
            'archived' => $retrospective,
            'empty' => $empty,
            'sold' => $this->insertSeats($symphony),
        ];
    }

    /**
     * @return array{buyer: User, event: int, seats: list<int>, price: int, spare: list<int>}
     */
    protected function seedPurchasable(int $seats = 2, int $spare = 1, int $priceCents = 7300, string $currency = 'EUR'): array
    {
        $organizer = User::factory()->create(['role' => UserRole::Organizer]);
        $buyer = User::factory()->create(['role' => UserRole::Buyer]);

        $venue = $this->insertVenue('Riverside Arena', '14 Quay Street', 'Rotterdam');
        $event = $this->insertEvent($organizer->id, $venue, 'Contended Gala', null, '2027-03-04 18:00:00', 'published');

        $rows = [];

        for ($number = 1; $number <= $seats + $spare; $number++) {
            $rows[] = [
                'event_id' => $event,
                'section' => 'A',
                'row' => 1,
                'number' => $number,
                'x' => $number * 40,
                'y' => 40,
                'price_cents' => $priceCents,
                'currency' => $currency,
                'status' => 'free',
            ];
        }

        DB::table('event_seats')->insert($rows);

        $all = DB::table('event_seats')
            ->where('event_id', $event)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        return [
            'buyer' => $buyer,
            'event' => $event,
            'seats' => array_values(array_slice($all, 0, $seats)),
            'price' => $priceCents,
            'spare' => array_values(array_slice($all, $seats)),
        ];
    }

    protected function insertTicket(int $eventId, int $eventSeatId, string $qrCode, string $status = 'issued', ?string $checkedInAt = null): string
    {
        $orderId = Str::uuid()->toString();
        $ticketId = Str::uuid()->toString();

        DB::table('orders')->insert([
            'id' => $orderId,
            'buyer_id' => User::factory()->create(['role' => UserRole::Buyer])->id,
            'event_id' => $eventId,
            'status' => 'paid',
            'total_cents' => 3500,
            'currency' => 'EUR',
            'idempotency_key' => 'ticket-'.$qrCode,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tickets')->insert([
            'id' => $ticketId,
            'order_id' => $orderId,
            'event_seat_id' => $eventSeatId,
            'qr_code' => $qrCode,
            'status' => $status,
            'checked_in_at' => $checkedInAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $ticketId;
    }

    private function insertVenue(string $name, string $address, string $city): int
    {
        return (int) DB::table('venues')->insertGetId([
            'name' => $name,
            'address' => $address,
            'city' => $city,
            'seat_map_template' => json_encode(['sections' => []], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertEvent(int $organizerId, int $venueId, string $title, ?string $description, string $startsAt, string $status): int
    {
        return (int) DB::table('events')->insertGetId([
            'organizer_id' => $organizerId,
            'venue_id' => $venueId,
            'title' => $title,
            'description' => $description,
            'starts_at' => $startsAt,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return list<int>
     */
    private function insertSeats(int $eventId): array
    {
        $rows = [];

        foreach (['B' => 3500, 'A' => 5000] as $section => $priceCents) {
            for ($row = 2; $row >= 1; $row--) {
                for ($number = 3; $number >= 1; $number--) {
                    $rows[] = [
                        'event_id' => $eventId,
                        'section' => (string) $section,
                        'row' => $row,
                        'number' => $number,
                        'x' => $section === 'A' ? $number * 40 : $number * 40 + 200,
                        'y' => $row * 40,
                        'price_cents' => $priceCents,
                        'currency' => 'EUR',
                        'status' => ($section === 'A' && $row === 1 && $number === 3) || ($section === 'B' && $row === 1 && $number === 1) ? 'sold' : 'free',
                    ];
                }
            }
        }

        DB::table('event_seats')->insert($rows);

        return DB::table('event_seats')
            ->where('event_id', $eventId)
            ->where('status', 'sold')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }
}
