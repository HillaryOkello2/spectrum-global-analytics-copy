<?php

namespace App\Http\Requests\Concerns;

use App\Enums\PaymentMethod;
use App\Support\KenyanMsisdn;
use Closure;
use Illuminate\Validation\Validator;

trait ValidatesMpesaPhone
{
    /**
     * M-Pesa can only prompt a Kenyan mobile. It is also the default method, so
     * a paid request that names no method needs one too. Card has no such limit.
     */
    protected function mpesaPhoneCheck(string $attribute, mixed $phone, bool $paid, PaymentMethod $default = PaymentMethod::Mpesa): Closure
    {
        return function (Validator $validator) use ($attribute, $phone, $paid, $default): void {
            $method = PaymentMethod::tryFrom((string) $this->input('payment_method')) ?? $default;

            if ($paid && $method === PaymentMethod::Mpesa && KenyanMsisdn::normalise(is_string($phone) ? $phone : null) === null) {
                $validator->errors()->add(
                    $attribute,
                    'M-Pesa needs a Kenyan mobile number (07…, 01… or +254…). Use one, or pay by card.',
                );
            }
        };
    }
}
