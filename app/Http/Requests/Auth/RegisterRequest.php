<?php

namespace App\Http\Requests\Auth;

use App\Enums\PaymentMethod;
use App\Http\Requests\Concerns\ValidatesMpesaPhone;
use App\Models\SubscriptionTier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    use ValidatesMpesaPhone;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'country' => ['required', 'string', 'max:100'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'tier' => ['required', 'string', 'exists:subscription_tiers,public_id'],
            'payment_method' => ['nullable', Rule::enum(PaymentMethod::class)],
        ];
    }

    /**
     * Only a paid tier takes a payment, so a free signup needs no M-Pesa number.
     */
    public function after(): array
    {
        $tier = is_string($this->input('tier'))
            ? SubscriptionTier::firstWhere('public_id', $this->input('tier'))
            : null;

        return [
            $this->mpesaPhoneCheck('phone', $this->input('phone'), paid: $tier !== null && ! $tier->isFree()),
        ];
    }
}
