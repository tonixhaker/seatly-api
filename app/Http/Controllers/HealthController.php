<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

final class HealthController extends Controller
{
    public function live(): JsonResponse
    {
        return new JsonResponse(['status' => 'ok']);
    }

    public function ready(): JsonResponse
    {
        try {
            DB::connection()->getPdo();
        } catch (Throwable) {
            return new JsonResponse(['status' => 'error', 'checks' => ['database' => 'error']], 503);
        }

        return new JsonResponse(['status' => 'ok', 'checks' => ['database' => 'ok']]);
    }
}
