<?php

declare(strict_types=1);

namespace App\Infrastructure\Realtime;

use App\Domain\Order\Contracts\HoldsValidatorInterface;
use App\Domain\Order\Exceptions\HoldsValidationUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Context;
use Psr\Log\LoggerInterface;

final readonly class HttpHoldsValidator implements HoldsValidatorInterface
{
    private const UNAVAILABLE_MESSAGE = 'Seat holds could not be verified. Please try again in a moment.';

    private const TOKEN_HEADER = 'X-Internal-Token';

    private const REQUEST_ID_HEADER = 'X-Request-Id';

    public function __construct(
        private Factory $http,
        private string $baseUrl,
        private string $internalToken,
        private float $timeoutSeconds,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param  list<int>  $seatIds
     * @return list<int>
     */
    public function missingSeats(int $eventId, array $seatIds, string $sessionId): array
    {
        $baseUrl = rtrim($this->baseUrl, '/');

        if ($baseUrl === '') {
            return $this->fail('error', 'unconfigured', $eventId, $seatIds);
        }

        $headers = [self::TOKEN_HEADER => $this->internalToken];
        $requestId = Context::get('request_id');

        if (is_string($requestId) && $requestId !== '') {
            $headers[self::REQUEST_ID_HEADER] = $requestId;
        }

        try {
            $response = $this->http
                ->withHeaders($headers)
                ->timeout($this->timeoutSeconds)
                ->connectTimeout($this->timeoutSeconds)
                ->get($baseUrl.'/internal/holds/validate?'.self::query($eventId, $seatIds, $sessionId));
        } catch (ConnectionException $failure) {
            return $this->fail('warning', 'unreachable', $eventId, $seatIds, null, self::withoutUrl($failure->getMessage()));
        }

        $status = $response->status();

        if ($status !== 200) {
            return $this->fail(
                'error',
                $status === 401 ? 'unauthorized' : 'unexpected_status',
                $eventId,
                $seatIds,
                $status,
            );
        }

        $missing = self::parseMissing($response->json('missing'));

        if ($missing === [] && $response->json('valid') === false) {
            $missing = null;
        }

        if ($missing === null) {
            return $this->fail('error', 'malformed_body', $eventId, $seatIds, $status);
        }

        return $missing;
    }

    /**
     * @param  list<int>  $seatIds
     */
    private static function query(int $eventId, array $seatIds, string $sessionId): string
    {
        $parts = ['event_id='.$eventId];

        foreach ($seatIds as $seatId) {
            $parts[] = 'seat_ids='.$seatId;
        }

        $parts[] = 'session_id='.rawurlencode($sessionId);

        return implode('&', $parts);
    }

    private static function withoutUrl(string $message): string
    {
        $cut = strpos($message, ' for http');

        return $cut === false ? $message : substr($message, 0, $cut);
    }

    /**
     * @return list<int>|null
     */
    private static function parseMissing(mixed $missing): ?array
    {
        if (! is_array($missing) || ! array_is_list($missing)) {
            return null;
        }

        foreach ($missing as $seatId) {
            if (! is_int($seatId)) {
                return null;
            }
        }

        /** @var list<int> $missing */
        return $missing;
    }

    /**
     * @param  list<int>  $seatIds
     *
     * @throws HoldsValidationUnavailableException
     */
    private function fail(
        string $level,
        string $reason,
        int $eventId,
        array $seatIds,
        ?int $status = null,
        ?string $detail = null,
    ): never {
        $context = ['reason' => $reason, 'event_id' => $eventId, 'seat_count' => count($seatIds)];

        if ($status !== null) {
            $context['status'] = $status;
        }

        if ($detail !== null) {
            $context['detail'] = $detail;
        }

        $this->logger->log($level, 'Seat holds could not be verified with seatly-realtime.', $context);

        throw new HoldsValidationUnavailableException(self::UNAVAILABLE_MESSAGE);
    }
}
