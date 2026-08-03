<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SyncRolePermissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manage users');
    }

    public function rules(): array
    {
        return [
            // An empty array is valid: it strips the role back to granting nothing.
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'name')],
        ];
    }

    public function messages(): array
    {
        return [
            'permissions.*.exists' => 'One or more of the supplied permissions does not exist.',
        ];
    }
}
