<?php

namespace App\Http\Requests\Subscriber;

use App\Enums\PaymentMethod;
use App\Http\Requests\Concerns\ValidatesMpesaPhone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RenewSubscriptionRequest extends FormRequest
{
    use ValidatesMpesaPhone;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'payment_method' => ['nullable', Rule::enum(PaymentMethod::class)],
        ];
    }

    public function after(): array
    {
        $tier = $this->user()?->subscriptions()->with('tier')->latest('id')->first()?->tier;

        return [
            $this->mpesaPhoneCheck('payment_method', $this->user()?->phone, paid: $tier !== null && ! $tier->isFree()),
        ];
    }
}
