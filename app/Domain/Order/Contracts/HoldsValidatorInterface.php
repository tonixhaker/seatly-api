<?php

declare(strict_types=1);

namespace App\Domain\Order\Contracts;

use App\Domain\Order\Exceptions\HoldsValidationUnavailableException;

interface HoldsValidatorInterface
{
    /**
     * @param  list<int>  $seatIds
     * @return list<int>
     *
     * @throws HoldsValidationUnavailableException
     */
    public function missingSeats(int $eventId, array $seatIds, string $sessionId): array;
}
