<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Event\DTO\EventDraftData;
use App\Domain\Event\Services\EventDraftService;
use App\Domain\Event\Services\EventPublishService;
use App\Domain\User\Enums\UserRole;
use App\Domain\User\Models\User;
use App\Domain\User\Repositories\UserRepositoryInterface;
use App\Domain\Venue\Models\Venue;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public const PASSWORD = 'password';

    public const ORGANIZER_EMAIL = 'organizer1@seatly.test';

    public const SECOND_ORGANIZER_EMAIL = 'organizer2@seatly.test';

    public const BUYER_EMAIL = 'buyer@seatly.test';

    public function run(
        UserRepositoryInterface $users,
        EventDraftService $drafts,
        EventPublishService $publishing,
    ): void {
        if ($users->findByEmail(self::ORGANIZER_EMAIL) !== null) {
            $this->announce('Demo data already present, nothing changed.');

            return;
        }

        $organizer = $this->user($users, 'Nora Vermeer', self::ORGANIZER_EMAIL, UserRole::Organizer);
        $partner = $this->user($users, 'Victor Bakker', self::SECOND_ORGANIZER_EMAIL, UserRole::Organizer);
        $this->user($users, 'Iris Janssen', self::BUYER_EMAIL, UserRole::Buyer);

        $arena = $this->venue('Riverside Arena', '14 Quay Street', 'Rotterdam', self::griddedSeatMap());
        $hall = $this->venue('Northgate Hall', '3 Market Square', 'Utrecht', self::curvedSeatMap());

        $symphony = $drafts->create($organizer, new EventDraftData(
            $arena,
            'Autumn Symphony',
            'An evening of late romantic repertoire.',
            self::startsAt(10, 3, 19, 30),
        ));

        $jazz = $drafts->create($partner, new EventDraftData(
            $hall,
            'Winter Jazz Night',
            'Three quartets across one long night.',
            self::startsAt(12, 12, 20, 30),
        ));

        $drafts->create($organizer, new EventDraftData(
            $arena,
            'Spring Gala Preview',
            'Not announced yet.',
            self::startsAt(3, 4, 18, 0),
        ));

        $publishing->publish($symphony->id, $organizer);
        $publishing->publish($jazz->id, $partner);

        $this->announceCredentials();
    }

    private function user(UserRepositoryInterface $users, string $name, string $email, UserRole $role): int
    {
        $user = new User;
        $user->fill([
            'name' => $name,
            'email' => $email,
            'password' => self::PASSWORD,
            'role' => $role,
        ]);

        $users->persist($user);

        return $user->id;
    }

    /**
     * @param  array{sections: list<array{name: string, seats: list<array{row: int, number: int, x: int, y: int, price_cents: int}>}>}  $seatMap
     */
    private function venue(string $name, string $address, string $city, array $seatMap): int
    {
        $venue = new Venue([
            'name' => $name,
            'address' => $address,
            'city' => $city,
            'seat_map_template' => $seatMap,
        ]);

        $venue->save();

        return $venue->id;
    }

    private static function startsAt(int $month, int $day, int $hour, int $minute): string
    {
        $now = Carbon::now('UTC');
        $date = $now->copy()->setDate($now->year, $month, $day)->setTime($hour, $minute);

        return ($date->isAfter($now) ? $date : $date->addYear())->toIso8601ZuluString();
    }

    /**
     * @return array{sections: list<array{name: string, seats: list<array{row: int, number: int, x: int, y: int, price_cents: int}>}>}
     */
    private static function griddedSeatMap(): array
    {
        $aisleGap = 60;
        $sections = [];

        foreach ([['Front Stalls', 1, 2, 8500], ['Rear Stalls', 3, 5, 4500]] as [$name, $firstRow, $lastRow, $priceCents]) {
            $seats = [];

            for ($row = $firstRow; $row <= $lastRow; $row++) {
                for ($number = 1; $number <= 8; $number++) {
                    $seats[] = [
                        'row' => $row,
                        'number' => $number,
                        'x' => $number * 40 + ($number > 4 ? $aisleGap : 0),
                        'y' => $row * 45,
                        'price_cents' => $priceCents,
                    ];
                }
            }

            $sections[] = ['name' => $name, 'seats' => $seats];
        }

        return ['sections' => $sections];
    }

    /**
     * @return array{sections: list<array{name: string, seats: list<array{row: int, number: int, x: int, y: int, price_cents: int}>}>}
     */
    private static function curvedSeatMap(): array
    {
        $seats = [];

        for ($row = 1; $row <= 4; $row++) {
            $radius = 180 + $row * 50;

            for ($number = 1; $number <= 9; $number++) {
                $angle = ($number - 5) * 0.15;

                $seats[] = [
                    'row' => $row,
                    'number' => $number,
                    'x' => (int) round(400 + $radius * sin($angle)),
                    'y' => (int) round(660 - $radius * cos($angle)),
                    'price_cents' => 6000,
                ];
            }
        }

        return ['sections' => [['name' => 'Gallery', 'seats' => $seats]]];
    }

    private function announceCredentials(): void
    {
        $this->command->table(
            ['Role', 'Email', 'Password'],
            [
                ['organizer', self::ORGANIZER_EMAIL, self::PASSWORD],
                ['organizer', self::SECOND_ORGANIZER_EMAIL, self::PASSWORD],
                ['buyer', self::BUYER_EMAIL, self::PASSWORD],
            ],
        );
    }

    private function announce(string $message): void
    {
        $this->command->info($message);
    }
}
