<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class SubmitProofreadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('proofread products');
    }

    public function rules(): array
    {
        return [
            // The document, and nothing else. Title and byline were fixed when
            // the product shell was created, and the abstract is lifted from
            // the body's own Executive Summary rather than retyped by hand.
            'body' => ['required', 'string'],
        ];
    }
}
