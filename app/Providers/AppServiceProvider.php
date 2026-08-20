<?php

declare(strict_types=1);

namespace App\Providers;

use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Server;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $this->requireInternalToken();

        JsonResource::withoutWrapping();

        Scramble::afterOpenApiGenerated(function (OpenApi $openApi): void {
            $openApi->servers = [new Server('/')];
        });
    }

    private function requireInternalToken(): void
    {
        if ($this->app->runningInConsole()) {
            return;
        }

        $token = config('realtime.internal_token');

        if (! is_string($token) || $token === '') {
            throw new RuntimeException('INTERNAL_TOKEN is empty; seatly-api refuses to serve requests without it.');
        }
    }
}
