<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Shared query parameters for the ranked analytics endpoints.
 */
class AnalyticsWindowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('view analytics');
    }

    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'limit' => ['nullable', 'integer', 'between:1,100'],
        ];
    }
}
