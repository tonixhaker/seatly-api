<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

it('runs migrate:fresh and unwinds every table with one migrate:rollback', function (): void {
    $business = ['users', 'venues', 'events', 'event_seats', 'orders', 'order_items', 'tickets'];

    expect(Artisan::call('migrate:fresh', ['--force' => true]))->toBe(0);

    foreach ($business as $table) {
        expect(Schema::hasTable($table))->toBeTrue();
    }

    expect(Artisan::call('migrate:rollback', ['--force' => true]))->toBe(0);

    foreach ($business as $table) {
        expect(Schema::hasTable($table))->toBeFalse();
    }

    expect(Schema::hasTable('migrations'))->toBeTrue();

    expect(Artisan::call('migrate:fresh', ['--force' => true]))->toBe(0);

    foreach ($business as $table) {
        expect(Schema::hasTable($table))->toBeTrue();
    }
});
