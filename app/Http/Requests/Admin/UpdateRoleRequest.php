<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdateRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manage users');
    }

    /**
     * `description` is only written when it is sent: omitting it leaves the
     * stored one alone, sending null clears it.
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('roles', 'name')->ignore($this->route('role')?->id),
            ],
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }
}
