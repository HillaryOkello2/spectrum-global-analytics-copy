<?php

namespace App\Http\Requests\Admin;

use App\Models\SubscriptionTier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdateTierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manage subscription tiers');
    }

    /**
     * Every field is optional — this is a PATCH, and a pricing screen that
     * sends only what changed must not clear the rest.
     */
    public function rules(): array
    {
        /** @var SubscriptionTier|null $tier */
        $tier = $this->route('tier');

        return [
            'name' => ['sometimes', 'string', 'max:100', Rule::unique('subscription_tiers', 'name')->ignore($tier?->id)],
            // A price of 0 makes the tier free: signup and renewal then skip the
            // gateway entirely, which is how Freemium works.
            'price' => ['sometimes', 'numeric', 'min:0', 'max:999999.99'],
            'currency' => ['sometimes', 'string', 'size:3', 'uppercase'],
            // Terms are added a month at a time throughout billing; anything
            // else here would be a label that does not match what is charged.
            'billing_period' => ['sometimes', Rule::in(['monthly'])],
            // Inactive hides the tier from the public pricing page and refuses
            // new signups and upgrades onto it (`tier_not_purchasable`).
            // Subscribers already on it keep their term.
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'billing_period.in' => 'Only monthly billing is supported; subscription terms are added a month at a time.',
        ];
    }
}
