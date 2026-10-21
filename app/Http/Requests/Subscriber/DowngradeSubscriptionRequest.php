<?php

namespace App\Http\Requests\Subscriber;

use Illuminate\Foundation\Http\FormRequest;

/**
 * No payment method, unlike its upgrade counterpart: a downgrade charges
 * nothing now, and whatever it costs next term is collected by the renewal.
 */
class DowngradeSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'tier' => ['required', 'string', 'exists:subscription_tiers,public_id'],
        ];
    }
}
