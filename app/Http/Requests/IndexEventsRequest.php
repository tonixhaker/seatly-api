<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class IndexEventsRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
            'starts_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'starts_until' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:starts_from'],
        ];
    }
}
