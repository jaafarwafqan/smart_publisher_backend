<?php

namespace App\Http\Requests\Platform;

use App\Enums\OrganizationRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class StorePlatformUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => [
                'required',
                'string',
                Password::min(12)->letters()->mixedCase()->numbers()->symbols(),
            ],
            'organization_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'membership_role' => ['required_with:organization_id', 'string', 'in:'.implode(',', array_map(fn (OrganizationRole $role) => $role->value, OrganizationRole::cases()))],
        ];
    }
}
