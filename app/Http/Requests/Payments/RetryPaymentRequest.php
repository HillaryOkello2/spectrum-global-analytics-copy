<?php

namespace App\Http\Requests\Payments;

use App\Enums\PaymentMethod;
use App\Http\Requests\Concerns\ValidatesMpesaPhone;
use App\Models\Payment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RetryPaymentRequest extends FormRequest
{
    use ValidatesMpesaPhone;

    /**
     * Public, like the status poll: the payment's UUID is the only key.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'payment_method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'phone' => ['nullable', 'string', 'max:30'],
        ];
    }

    public function after(): array
    {
        /** @var Payment $payment */
        $payment = $this->route('payment');

        return [
            $this->mpesaPhoneCheck(
                'phone',
                $this->input('phone') ?? $payment->phone ?? $payment->user->phone,
                paid: true,
                default: $payment->method,
            ),
        ];
    }
}
