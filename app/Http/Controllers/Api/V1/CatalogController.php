<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\IndexEventsRequest;
use App\Http\Resources\EventDetailResource;
use App\Http\Resources\EventResource;
use App\Http\Resources\SeatResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @phpstan-type VenueFixture object{id: int, name: string, address: string, city: string}
 * @phpstan-type EventFixture object{id: int, title: string, description: string, starts_at: string, status: string, venue: VenueFixture}
 * @phpstan-type SeatFixture object{id: int, section: string, row: int, number: int, x: int, y: int, price_cents: int, currency: string, status: string}
 */
final class CatalogController extends Controller
{
    /**
     * List published events.
     */
    public function index(IndexEventsRequest $request): AnonymousResourceCollection
    {
        $from = $request->string('starts_from')->toString();
        $until = $request->string('starts_until')->toString();

        $events = array_values(array_filter(
            self::publishedEvents(),
            fn (object $event): bool => ($from === '' || substr($event->starts_at, 0, 10) >= $from)
                && ($until === '' || substr($event->starts_at, 0, 10) <= $until),
        ));

        $perPage = $request->integer('per_page') ?: 15;
        $page = $request->integer('page') ?: 1;

        $paginator = new LengthAwarePaginator(
            array_slice($events, ($page - 1) * $perPage, $perPage),
            count($events),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        return EventResource::collection($paginator);
    }

    /**
     * Return one published event with its venue.
     *
     * @throws NotFoundHttpException
     */
    public function show(int $id): EventDetailResource
    {
        return new EventDetailResource(self::publishedEvent($id));
    }

    /**
     * Return the seat map snapshot for an event.
     *
     * @throws NotFoundHttpException
     */
    public function seats(int $id): AnonymousResourceCollection
    {
        self::publishedEvent($id);

        return SeatResource::collection(self::seatFixtures());
    }

    /**
     * @return EventFixture
     */
    private static function publishedEvent(int $id): object
    {
        foreach (self::publishedEvents() as $event) {
            if ($event->id === $id) {
                return $event;
            }
        }

        abort(404);
    }

    /**
     * @return list<EventFixture>
     */
    private static function publishedEvents(): array
    {
        return array_values(array_filter(
            self::eventFixtures(),
            fn (object $event): bool => $event->status === 'published',
        ));
    }

    /**
     * @return list<EventFixture>
     */
    private static function eventFixtures(): array
    {
        $arena = (object) ['id' => 1, 'name' => 'Riverside Arena', 'address' => '14 Quay Street', 'city' => 'Rotterdam'];
        $hall = (object) ['id' => 2, 'name' => 'Northgate Hall', 'address' => '3 Market Square', 'city' => 'Utrecht'];

        return [
            (object) ['id' => 1, 'title' => 'Autumn Symphony', 'description' => 'An evening of late romantic repertoire.', 'starts_at' => '2026-10-01T19:00:00Z', 'status' => 'published', 'venue' => $arena],
            (object) ['id' => 2, 'title' => 'Winter Jazz Night', 'description' => 'Three quartets across one long night.', 'starts_at' => '2026-12-12T20:30:00Z', 'status' => 'published', 'venue' => $hall],
            (object) ['id' => 3, 'title' => 'Spring Gala', 'description' => 'Not announced yet.', 'starts_at' => '2027-03-04T18:00:00Z', 'status' => 'draft', 'venue' => $arena],
            (object) ['id' => 4, 'title' => 'Summer Retrospective', 'description' => 'Concluded last season.', 'starts_at' => '2026-06-20T19:30:00Z', 'status' => 'archived', 'venue' => $hall],
        ];
    }

    /**
     * @return list<SeatFixture>
     */
    private static function seatFixtures(): array
    {
        $sold = [3, 7, 11];
        $seats = [];
        $id = 1;

        foreach (['A' => 5000, 'B' => 3500] as $section => $priceCents) {
            for ($row = 1; $row <= 2; $row++) {
                for ($number = 1; $number <= 3; $number++) {
                    $seats[] = (object) [
                        'id' => $id,
                        'section' => (string) $section,
                        'row' => $row,
                        'number' => $number,
                        'x' => $section === 'A' ? $number * 40 : $number * 40 + 200,
                        'y' => $row * 40,
                        'price_cents' => $priceCents,
                        'currency' => 'EUR',
                        'status' => in_array($id, $sold, true) ? 'sold' : 'free',
                    ];
                    $id++;
                }
            }
        }

        return $seats;
    }
}
