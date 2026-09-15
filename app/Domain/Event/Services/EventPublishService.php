<?php

declare(strict_types=1);

namespace App\Domain\Event\Services;

use App\Domain\Event\Contracts\EventPublisherInterface;
use App\Domain\Event\DTO\EventData;
use App\Domain\Event\DTO\SeatBlueprint;
use App\Domain\Event\Enums\EventStatus;
use App\Domain\Event\Events\EventPublished;
use App\Domain\Event\Exceptions\InvalidEventTransitionException;
use App\Domain\Event\Repositories\EventRepositoryInterface;
use App\Domain\Venue\Exceptions\InvalidSeatMapTemplateException;

final readonly class EventPublishService
{
    private const CURRENCY = 'EUR';

    public function __construct(
        private EventRepositoryInterface $events,
        private EventPublisherInterface $publisher,
    ) {}

    public function publish(int $id, int $organizerId): ?EventData
    {
        $event = $this->events->findOwnedByOrganizer($id, $organizerId);

        if ($event === null) {
            return null;
        }

        self::assertDraft($event->status);

        $seats = self::blueprints($event->venue->getAttribute('seat_map_template'));

        $seatIds = $this->events->publishWithSeats($event, $seats);

        if ($seatIds === null) {
            throw new InvalidEventTransitionException(
                'Only a draft event can be edited or published.',
                ['status' => EventStatus::Published->value],
            );
        }

        $event->fill(['status' => EventStatus::Published]);

        $this->publisher->publish(new EventPublished($event->id, $seatIds));

        return EventData::fromModel($event);
    }

    private static function assertDraft(EventStatus $status): void
    {
        if ($status !== EventStatus::Draft) {
            throw new InvalidEventTransitionException(
                'Only a draft event can be edited or published.',
                ['status' => $status->value],
            );
        }
    }

    /**
     * @return list<SeatBlueprint>
     */
    private static function blueprints(mixed $template): array
    {
        if (! is_array($template)) {
            throw self::malformed('The venue seat map template is not an object.');
        }

        $sections = $template['sections'] ?? null;

        if (! is_array($sections) || $sections === []) {
            throw self::malformed('The venue seat map template declares no sections.');
        }

        $blueprints = [];
        $names = [];

        foreach ($sections as $section) {
            if (! is_array($section)) {
                throw self::malformed('A section of the venue seat map template is not an object.');
            }

            $name = $section['name'] ?? null;

            if (! is_string($name) || $name === '') {
                throw self::malformed('A section of the venue seat map template has no name.');
            }

            if (isset($names[$name])) {
                throw self::malformed('The venue seat map template names the same section twice.');
            }

            $names[$name] = true;

            foreach (self::sectionBlueprints($name, $section['seats'] ?? null) as $blueprint) {
                $blueprints[] = $blueprint;
            }
        }

        return $blueprints;
    }

    /**
     * @return list<SeatBlueprint>
     */
    private static function sectionBlueprints(string $section, mixed $seats): array
    {
        if (! is_array($seats) || $seats === []) {
            throw self::malformed('Section "'.$section.'" of the venue seat map template has no seats.');
        }

        $blueprints = [];
        $positions = [];

        foreach ($seats as $seat) {
            if (! is_array($seat)) {
                throw self::malformed('A seat in section "'.$section.'" is not an object.');
            }

            $row = self::coordinate($section, $seat, 'row');
            $number = self::coordinate($section, $seat, 'number');
            $position = $row.':'.$number;

            if (isset($positions[$position])) {
                throw self::malformed('Section "'.$section.'" places two seats at row '.$row.', number '.$number.'.');
            }

            $positions[$position] = true;

            $blueprints[] = new SeatBlueprint(
                section: $section,
                row: $row,
                number: $number,
                x: self::coordinate($section, $seat, 'x'),
                y: self::coordinate($section, $seat, 'y'),
                price_cents: self::coordinate($section, $seat, 'price_cents'),
                currency: self::CURRENCY,
            );
        }

        return $blueprints;
    }

    /**
     * @param  array<mixed, mixed>  $seat
     */
    private static function coordinate(string $section, array $seat, string $key): int
    {
        $value = $seat[$key] ?? null;

        if (! is_int($value)) {
            throw self::malformed('A seat in section "'.$section.'" has no integer "'.$key.'".');
        }

        return $value;
    }

    private static function malformed(string $message): InvalidSeatMapTemplateException
    {
        return new InvalidSeatMapTemplateException($message);
    }
}
