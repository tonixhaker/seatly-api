<?php

declare(strict_types=1);

namespace App\Domain\Event\Enums;

enum EventStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';
}
