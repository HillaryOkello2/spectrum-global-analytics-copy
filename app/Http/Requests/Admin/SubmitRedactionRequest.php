<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class SubmitRedactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('proofread products');
    }

    public function rules(): array
    {
        return [
            'redacted_body' => ['required', 'string'],
        ];
    }
}
