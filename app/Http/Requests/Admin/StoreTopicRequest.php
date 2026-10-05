<?php

namespace App\Http\Requests\Admin;

use App\Enums\Frequency;
use App\Models\Component;
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
     * A title and a component are all an editor has to decide. The cadence
     * comes from the component, and the prompt variables are filled from the
     * title at generation time (see TopicVariableFiller) — so the only thing
     * that can still be missing is a prompt for a component that has no
     * template of its own.
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (filled($this->input('prompt_text'))) {
                    return;
                }

                $component = is_string($this->input('component'))
                    ? Component::firstWhere('public_id', $this->input('component'))
                    : null;

                if ($component !== null && blank($component->prompt_template)) {
                    $validator->errors()->add(
                        'prompt_text',
                        "The {$component->name} has no prompt template of its own, so this topic needs a prompt_text.",
                    );
                }
            },
        ];
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'component' => ['required', 'string', 'exists:components,public_id'],
            // Optional: defaults to the component's own cadence, or monthly for
            // a component the client gave no cadence.
            'frequency' => ['nullable', Rule::enum(Frequency::class)],
            // Optional since the client's prompt pack: omit them and the
            // component's own template is rendered instead. Send them only to
            // override the series prompt for a one-off topic.
            'prompt_text' => ['nullable', 'string'],
            'qa_prompt_text' => ['nullable', 'string'],
            // Values for the component's declared placeholders, e.g.
            // {"PRIMARY_TOPIC": "..."}. Anything omitted is derived from the
            // title, or asked of the component's model, at generation time.
            'variables' => ['nullable', 'array'],
            'variables.*' => ['required', 'string', 'max:2000'],
        ];
    }
}
