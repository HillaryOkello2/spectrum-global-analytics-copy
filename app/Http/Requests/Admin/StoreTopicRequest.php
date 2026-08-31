<?php

namespace App\Http\Requests\Admin;

use App\Enums\Frequency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreTopicRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manage topics');
    }

    /**
     * A topic must end up with *something* to send the model: either its own
     * prompt, or variables to render the component's template with.
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (filled($this->input('prompt_text')) || filled($this->input('variables'))) {
                    return;
                }

                $validator->errors()->add(
                    'variables',
                    'Provide variables for the component prompt template, or a prompt_text of your own.',
                );
            },
        ];
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'component' => ['required', 'string', 'exists:components,public_id'],
            'frequency' => ['required', Rule::enum(Frequency::class)],
            // Optional since the client's prompt pack: omit them and the
            // component's own template is rendered instead. Send them only to
            // override the series prompt for a one-off topic.
            'prompt_text' => ['nullable', 'string'],
            'qa_prompt_text' => ['nullable', 'string'],
            // Values for the component's declared placeholders, e.g.
            // {"PRIMARY_TOPIC": "..."}. Required when no prompt_text is given —
            // see withValidator().
            'variables' => ['nullable', 'array'],
            'variables.*' => ['required', 'string', 'max:2000'],
        ];
    }
}
