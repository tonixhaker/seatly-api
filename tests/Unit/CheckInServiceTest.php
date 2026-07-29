<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\ErrorCode;
use App\Domain\Ticket\DTO\TicketData;
use App\Domain\Ticket\Exceptions\AlreadyCheckedInException;
use App\Domain\Ticket\Repositories\TicketRepositoryInterface;
use App\Domain\Ticket\Services\CheckInService;

$ticket = fn (string $status, ?string $checkedInAt): TicketData => new TicketData(
    '9b2f4c1e-7a3d-4e5f-8a6b-1c2d3e4f5a6b',
    'f47ac10b-58cc-4372-a567-0e02b2c3d479',
    11,
    'A1B2C3D4E5F6',
    $status,
    $checkedInAt,
    1,
    'Autumn Symphony',
    '2026-10-01T19:00:00Z',
    'B',
    1,
    1,
);

$repository = function (array $reads, bool $won): TicketRepositoryInterface {
    return new class($reads, $won) implements TicketRepositoryInterface
    {
        /** @var list<array{string, int}> */
        public array $lookups = [];

        /** @var list<string> */
        public array $marked = [];

        /** @param list<TicketData|null> $reads */
        public function __construct(private array $reads, private readonly bool $won) {}

        public function findForOrganizer(string $qrCode, int $organizerId): ?TicketData
        {
            $this->lookups[] = [$qrCode, $organizerId];

            return array_shift($this->reads);
        }

        public function markCheckedIn(string $ticketId): bool
        {
            $this->marked[] = $ticketId;

            return $this->won;
        }
    };
};

it('returns null and never marks anything when the ticket is not found for the organizer', function () use ($repository): void {
    $tickets = $repository([null], true);

    $result = (new CheckInService($tickets))->checkIn('Z9Y8X7W6V5U4', 10);

    expect($result)->toBeNull()
        ->and($tickets->marked)->toBe([])
        ->and($tickets->lookups)->toBe([['Z9Y8X7W6V5U4', 10]]);
});

it('returns the re-read ticket, not the first read, when the conditional update wins', function () use ($repository, $ticket): void {
    $reread = $ticket('checked_in', '2026-10-01T19:05:00Z');
    $tickets = $repository([$ticket('issued', null), $reread], true);

    $result = (new CheckInService($tickets))->checkIn('A1B2C3D4E5F6', 10);

    expect($result)->toBe($reread)
        ->and($result?->status)->toBe('checked_in')
        ->and($result?->checked_in_at)->toBe('2026-10-01T19:05:00Z');
});

it('marks exactly the ticket id it found, once', function () use ($repository, $ticket): void {
    $tickets = $repository([$ticket('issued', null), $ticket('checked_in', '2026-10-01T19:05:00Z')], true);

    (new CheckInService($tickets))->checkIn('A1B2C3D4E5F6', 10);

    expect($tickets->marked)->toBe(['9b2f4c1e-7a3d-4e5f-8a6b-1c2d3e4f5a6b'])
        ->and($tickets->lookups)->toBe([['A1B2C3D4E5F6', 10], ['A1B2C3D4E5F6', 10]]);
});

it('throws ALREADY_CHECKED_IN carrying the stored first check-in time when the update loses', function () use ($repository, $ticket): void {
    $tickets = $repository([$ticket('issued', null), $ticket('checked_in', '2026-10-01T18:42:07Z')], false);

    try {
        (new CheckInService($tickets))->checkIn('A1B2C3D4E5F6', 10);
        $this->fail('Expected AlreadyCheckedInException.');
    } catch (AlreadyCheckedInException $exception) {
        expect($exception->errorCode())->toBe(ErrorCode::ALREADY_CHECKED_IN)
            ->and($exception->details)->toBe(['checked_in_at' => '2026-10-01T18:42:07Z'])
            ->and($tickets->marked)->toBe(['9b2f4c1e-7a3d-4e5f-8a6b-1c2d3e4f5a6b']);
    }
});
