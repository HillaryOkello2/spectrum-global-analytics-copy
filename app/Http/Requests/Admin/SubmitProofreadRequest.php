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
            // The model's metadata is a first draft like the rest of the
            // document, so the proofreader can correct it here. Title is
            // required because it is what the catalogue lists.
            'title' => ['required', 'string', 'max:255'],
            'byline' => ['nullable', 'string', 'max:500'],
            // Written by the proofreader, never by the LLM. It is the public
            // preview, so it is mandatory before a product can be released.
            'abstract' => ['required', 'string', 'max:5000'],
            'body' => ['required', 'string'],
        ];
    }
}
