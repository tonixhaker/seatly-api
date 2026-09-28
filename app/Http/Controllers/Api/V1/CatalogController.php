<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Event\DTO\EventFilter;
use App\Domain\Event\Services\EventCatalogService;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexEventsRequest;
use App\Http\Resources\EventDetailResource;
use App\Http\Resources\EventResource;
use App\Http\Resources\SeatResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class CatalogController extends Controller
{
    public function __construct(private readonly EventCatalogService $catalog) {}

    /**
     * List published events.
     */
    public function index(IndexEventsRequest $request): AnonymousResourceCollection
    {
        $page = $this->catalog->listPublished(new EventFilter(
            starts_from: $request->string('starts_from')->toString() ?: null,
            starts_until: $request->string('starts_until')->toString() ?: null,
            page: $request->integer('page') ?: 1,
            per_page: $request->integer('per_page') ?: 15,
        ));

        return EventResource::collection($page->withQueryString());
    }

    /**
     * Return one published event with its venue.
     *
     * @throws NotFoundHttpException
     */
    public function show(int $id): EventDetailResource
    {
        $event = $this->catalog->findPublished($id);

        if ($event === null) {
            abort(404);
        }

        return new EventDetailResource($event);
    }

    /**
     * Return the seat map snapshot for an event.
     *
     * @throws NotFoundHttpException
     */
    public function seats(int $id): AnonymousResourceCollection
    {
        $seats = $this->catalog->seatsForPublished($id);

        if ($seats === null) {
            abort(404);
        }

        return SeatResource::collection($seats);
    }
}
