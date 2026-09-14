<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class PlaceOrderRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'event_id' => ['required', 'numeric', 'integer', 'min:1'],
            'seat_ids' => ['required', 'array', 'list', 'min:1', 'max:50'],
            'seat_ids.*' => ['required', 'numeric', 'integer', 'min:1', 'distinct'],
            'session_id' => ['required', 'uuid'],
            'idempotency_key' => ['required', 'string', 'max:128'],
        ];
    }
}
