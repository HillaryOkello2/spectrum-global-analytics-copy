<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class OverrideQaFailureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('proofread products');
    }

    public function rules(): array
    {
        return [
            // Required, and recorded on the audit log entry. Overriding a
            // failed audit is a judgement somebody has to own.
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ];
    }
}
