<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

final class EnvelopeSchemaValidator
{
    private const SCHEMA_PREFIX = 'https://schemas.seatly.dev/events/';

    private ?Validator $validator = null;

    public function __construct(private readonly string $schemaDirectory) {}

    public function assertValid(EventEnvelope $envelope): void
    {
        $schemaFile = $this->schemaDirectory.'/'.$envelope->event_type.'.json';

        if (! is_file($schemaFile)) {
            throw new InvalidEventEnvelopeException(
                sprintf('No schema is published for event type "%s".', $envelope->event_type),
            );
        }

        $result = $this->validator()->validate(
            json_decode($envelope->toJson(), false, 512, JSON_THROW_ON_ERROR),
            self::SCHEMA_PREFIX.$envelope->event_type.'.json',
        );

        $error = $result->error();

        if ($error === null) {
            return;
        }

        throw new InvalidEventEnvelopeException(sprintf(
            'A %s message does not match its schema: %s',
            $envelope->event_type,
            (string) json_encode((new ErrorFormatter)->format($error), JSON_THROW_ON_ERROR),
        ));
    }

    private function validator(): Validator
    {
        if ($this->validator instanceof Validator) {
            return $this->validator;
        }

        $validator = new Validator;
        $validator->resolver()?->registerPrefix(self::SCHEMA_PREFIX, $this->schemaDirectory);

        return $this->validator = $validator;
    }
}
