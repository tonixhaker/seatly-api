<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

use LogicException;

final class InvalidEventEnvelopeException extends LogicException {}
