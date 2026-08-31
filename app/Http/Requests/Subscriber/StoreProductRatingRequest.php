<?php

namespace App\Http\Requests\Subscriber;

use App\Models\ProductRating;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRatingRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route-level `role:subscriber` handles who may rate at all; whether they
        // may rate *this* product depends on entitlement and is decided in the
        // controller, where the product is resolved.
        return true;
    }

    public function rules(): array
    {
        return [
            'stars' => [
                'required',
                'integer',
                Rule::numeric()->between(ProductRating::MIN_STARS, ProductRating::MAX_STARS),
            ],
        ];
    }
}
