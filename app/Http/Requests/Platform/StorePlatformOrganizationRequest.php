<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StorePlatformOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'owner_user_id' => ['nullable', 'integer', 'exists:users,id', 'required_without:new_owner'],
            'new_owner' => ['nullable', 'array', 'required_without:owner_user_id'],
            'new_owner.name' => ['required_with:new_owner', 'string', 'max:255'],
            'new_owner.email' => ['required_with:new_owner', 'email', 'max:255', 'unique:users,email'],
            'new_owner.password' => [
                'required_with:new_owner',
                'string',
                Password::min(12)->letters()->mixedCase()->numbers()->symbols(),
            ],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ];
    }
}
