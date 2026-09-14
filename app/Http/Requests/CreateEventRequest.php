<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CreateEventRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'venue_id' => ['required', 'numeric', 'integer', 'min:1'],
            'title' => ['required', 'string', 'min:1', 'max:255', 'not_regex:/[\\x00-\\x1F\\x7F]/'],
            'description' => ['nullable', 'string', 'max:2000', 'not_regex:/\\x00/'],
            'starts_at' => ['required', 'regex:/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(Z|[+-](?:0\\d|1[0-4]):[0-5]\\d)$/', 'date_format:Y-m-d\TH:i:sP,Y-m-d\TH:i:s\Z', 'after:now'],
        ];
    }
}
