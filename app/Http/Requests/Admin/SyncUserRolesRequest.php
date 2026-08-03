<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SyncUserRolesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manage users');
    }

    public function rules(): array
    {
        return [
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', Rule::in(User::staffRoleNames())],
        ];
    }

    public function messages(): array
    {
        return [
            'roles.*.in' => 'Only staff roles can be assigned — `subscriber` is not one, and the role must exist.',
        ];
    }
}
