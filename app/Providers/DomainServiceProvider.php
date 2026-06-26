<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Event\Repositories\EventRepositoryInterface;
use App\Domain\User\Repositories\UserRepositoryInterface;
use App\Domain\Venue\Repositories\VenueRepositoryInterface;
use App\Infrastructure\Persistence\EloquentEventRepository;
use App\Infrastructure\Persistence\EloquentUserRepository;
use App\Infrastructure\Persistence\EloquentVenueRepository;
use Illuminate\Support\ServiceProvider;

final class DomainServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(EventRepositoryInterface::class, EloquentEventRepository::class);
        $this->app->bind(VenueRepositoryInterface::class, EloquentVenueRepository::class);
        $this->app->bind(UserRepositoryInterface::class, EloquentUserRepository::class);
    }
}
