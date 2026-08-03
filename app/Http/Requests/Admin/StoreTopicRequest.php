<?php

namespace App\Http\Requests\Admin;

use App\Enums\Frequency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreTopicRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manage topics');
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'component' => ['required', 'string', 'exists:components,public_id'],
            'frequency' => ['required', Rule::enum(Frequency::class)],
            'prompt_text' => ['required', 'string'],
            'qa_prompt_text' => ['required', 'string'],
        ];
    }
}
