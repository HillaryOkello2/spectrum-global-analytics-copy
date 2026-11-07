<?php

namespace App\Http\Requests\Subscriber;

use App\Services\Notifications\NotificationPreferences;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateNotificationPreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'newArticles' => ['sometimes', Rule::in([
                NotificationPreferences::EACH,
                NotificationPreferences::DAILY,
                NotificationPreferences::NONE,
            ])],
            'components' => ['sometimes', 'nullable', 'array'],
            'components.*' => ['string', 'max:10'],
        ];
    }
}
