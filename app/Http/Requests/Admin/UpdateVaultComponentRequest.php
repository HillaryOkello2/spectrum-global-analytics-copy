<?php

namespace App\Http\Requests\Admin;

use App\Models\Component;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdateVaultComponentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manage vault');
    }

    public function rules(): array
    {
        /** @var Component|null $component */
        $component = $this->route('component');

        return [
            'color' => [
                'sometimes',
                'nullable',
                'string',
                'max:32',
                Rule::in([
                    'component-1', 'component-2', 'component-3', 'component-4',
                    'component-5', 'component-6', 'component-7', 'component-8',
                    'component-9', 'component-10', 'component-11', 'component-12',
                ]),
                Rule::unique('components', 'color')->ignore($component?->id),
            ],
        ];
    }
}
