<?php

namespace App\Http\Requests\Admin;

use App\Enums\AccessType;
use App\Models\Component;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SyncTierAllocationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manage subscription tiers');
    }

    public function rules(): array
    {
        return [
            // An empty array is valid: a tier that unlocks nothing yet.
            'allocations' => ['present', 'array'],
            'allocations.*.component' => ['required', 'string'],
            'allocations.*.access_type' => ['required', Rule::enum(AccessType::class)],
            'allocations.*.monthly_limit' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ];
    }

    /**
     * A limit only means something on a metered allocation, and a metered one
     * is meaningless without it — an allocation that disagrees with itself
     * would meter against null and deny everything.
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $seen = [];

                foreach ((array) $this->input('allocations', []) as $index => $row) {
                    $component = is_array($row) && is_string($row['component'] ?? null)
                        ? Component::query()
                            ->where('public_id', $row['component'])
                            ->orWhere('code', $row['component'])
                            ->first()
                        : null;

                    if ($component === null) {
                        $validator->errors()->add("allocations.{$index}.component", 'No component matches this code or publicId.');

                        continue;
                    }

                    if (in_array($component->id, $seen, true)) {
                        $validator->errors()->add("allocations.{$index}.component", "{$component->code} appears more than once.");
                    }

                    $seen[] = $component->id;

                    $metered = ($row['access_type'] ?? null) === AccessType::Metered->value;
                    $limit = $row['monthly_limit'] ?? null;

                    if ($metered && $limit === null) {
                        $validator->errors()->add("allocations.{$index}.monthly_limit", 'A metered allocation needs a monthly limit.');
                    }

                    if (! $metered && $limit !== null) {
                        $validator->errors()->add(
                            "allocations.{$index}.monthly_limit",
                            'Only a metered allocation takes a monthly limit.',
                        );
                    }
                }
            },
        ];
    }
}
