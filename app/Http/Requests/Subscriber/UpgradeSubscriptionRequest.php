<?php

namespace App\Http\Requests\Subscriber;

use App\Enums\PaymentMethod;
use App\Http\Requests\Concerns\ValidatesMpesaPhone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpgradeSubscriptionRequest extends FormRequest
{
    use ValidatesMpesaPhone;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'tier' => ['required', 'string', 'exists:subscription_tiers,public_id'],
            'payment_method' => ['nullable', Rule::enum(PaymentMethod::class)],
        ];
    }

    /**
     * Upgrades always target a higher-priced tier, so they are always paid.
     */
    public function after(): array
    {
        return [
            $this->mpesaPhoneCheck('payment_method', $this->user()?->phone, paid: true),
        ];
    }
}
